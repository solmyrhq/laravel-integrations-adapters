<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub;

use Github\Exception\ApiLimitExceedException;
use Github\Exception\RuntimeException as GitHubRuntimeException;
use GuzzleHttp\Exception\ConnectException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Integrations\Adapters\GitHub\Data\GitHubIssueData;
use Integrations\Adapters\GitHub\Data\GitHubUserData;
use Integrations\Adapters\GitHub\Events\GitHubIssueSynced;
use Integrations\Concerns\ReducesCheckpointsByMax;
use Integrations\Contracts\ClassifiesFailures;
use Integrations\Contracts\CustomizesRetry;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\HasIncrementalSync;
use Integrations\Contracts\IdentifiesAuthenticatedUser;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Contracts\RedactsRequestData;
use Integrations\Data\AuthenticatedUser;
use Integrations\Enums\FailureClass;
use Integrations\Models\Integration;
use Integrations\RateLimit;
use Integrations\Sync\SyncSession;
use InvalidArgumentException;

use function Safe\preg_match;

class GitHubProvider implements ClassifiesFailures, CustomizesRetry, HasHealthCheck, HasIncrementalSync, IdentifiesAuthenticatedUser, IntegrationProvider, RedactsRequestData
{
    use ReducesCheckpointsByMax;

    #[\Override]
    public function classifyFailure(\Throwable $e): ?FailureClass
    {
        // Hitting the hourly budget, or the secondary 403 rate limit, means
        // GitHub is healthy and throttling us — not an upstream fault.
        if ($e instanceof ApiLimitExceedException) {
            return FailureClass::Throttle;
        }

        if ($e instanceof ConnectException) {
            return FailureClass::Upstream;
        }

        if ($e instanceof GitHubRuntimeException) {
            $code = $e->getCode();

            if ($code === 429 || ($code === 403 && self::isRateLimitMessage($e->getMessage()))) {
                return FailureClass::Throttle;
            }

            if ($code >= 100 && $code <= 599) {
                return FailureClass::fromStatus($code);
            }
        }

        return null;
    }

    #[\Override]
    public function isRetryable(\Throwable $e): ?bool
    {
        // Retryability is now derived from classification (core falls back to
        // FailureClass::isRetryable() when this returns null), so we only keep
        // CustomizesRetry for the reset-time backoff in retryDelayMs().
        return null;
    }

    #[\Override]
    public function retryDelayMs(\Throwable $e, int $attempt, ?int $statusCode): ?int
    {
        if ($e instanceof ApiLimitExceedException) {
            return max($e->getResetTime() - time(), 1) * 1000;
        }

        return null;
    }

    #[\Override]
    public function name(): string
    {
        return 'GitHub';
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function credentialRules(): array
    {
        return [
            'token' => ['required', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function metadataRules(): array
    {
        return [
            'owner' => ['required', 'string'],
            'repo' => ['required', 'string'],
        ];
    }

    /**
     * @return class-string<GitHubCredentials>
     */
    #[\Override]
    public function credentialDataClass(): string
    {
        return GitHubCredentials::class;
    }

    /**
     * @return class-string<GitHubMetadata>
     */
    #[\Override]
    public function metadataDataClass(): string
    {
        return GitHubMetadata::class;
    }

    #[\Override]
    public function sync(Integration $integration, SyncSession $session): void
    {
        // A full re-sync ignores the cursor and enumerates from the epoch.
        // The framework only calls this for non-incremental providers; for
        // GitHub it's a fallback, since the provider is HasIncrementalSync.
        $this->enumerate($this->makeClient($integration), $integration, $session, Carbon::createFromTimestamp(0));
    }

    #[\Override]
    public function syncIncremental(Integration $integration, SyncSession $session): void
    {
        $cursor = $session->cursor();

        if ($cursor === null || $cursor === '') {
            $since = Carbon::createFromTimestamp(0);
        } else {
            if (! is_string($cursor)) {
                throw new InvalidArgumentException('GitHubProvider::syncIncremental() expects the cursor to be a string or null, got '.get_debug_type($cursor).'.');
            }

            $parsed = self::parseTimestamp($cursor);
            if ($parsed === null) {
                throw new InvalidArgumentException("GitHubProvider::syncIncremental() received an unparseable cursor: '{$cursor}'.");
            }

            // Subtract a 1-hour overlap buffer to catch issues updated between
            // runs. The framework's cursor advance is monotonic, so
            // re-presenting items inside that window can't regress progress.
            $since = $parsed->subHour();
        }

        $client = $this->makeClient($integration);
        $listed = $this->enumerate($client, $integration, $session, $since);

        if ($cursor !== null && $cursor !== '') {
            $this->recoverIssuesFromComments($client, $integration, $session, $since, $listed);
        }
    }

    /**
     * @return array<int, true> the numbers of the dispatched issues, as keys
     */
    private function enumerate(GitHubClient $client, Integration $integration, SyncSession $session, Carbon $since): array
    {
        $listed = [];

        $client->issues()->since($since, function (array $issue) use ($integration, $session, &$listed): void {
            $number = $this->dispatchIssue($integration, $session, $issue);

            if ($number !== null) {
                $listed[$number] = true;
            }
        });

        return $listed;
    }

    /**
     * Syncs each issue that has a comment in the `since` window but was missing from the issues list.
     *
     * GitHub can take more than an hour to add a recently updated issue to the issues list, by which time its
     * `updated_at` is before the window and no later run lists it. This relies on the comments feed not having
     * the same delay.
     *
     * @param  array<int, true>  $listed  the numbers of the issues already dispatched from the issues list, as keys
     */
    private function recoverIssuesFromComments(
        GitHubClient $client,
        Integration $integration,
        SyncSession $session,
        Carbon $since,
        array $listed,
    ): void {
        $missing = [];

        $client->comments()->since($since, function (array $comment) use ($listed, &$missing): void {
            $number = self::issueNumberOfComment($comment);

            if ($number !== null && ! array_key_exists($number, $listed)) {
                $missing[$number] = true;
            }
        });

        foreach (array_keys($missing) as $number) {
            $issue = $client->issues()->get($number);

            if ($issue === null || array_key_exists('pull_request', $issue)) {
                continue;
            }

            Log::warning('GitHubProvider: an issue with comments updated in the `since` window was missing from the issues list, so the provider is syncing it from the comments feed.', [
                'integration_id' => $integration->id,
                'issue_number' => $number,
                'since' => $since->toIso8601String(),
            ]);

            $this->dispatchIssue($integration, $session, $issue);
        }
    }

    /**
     * @param  array<string, mixed>  $issue
     * @return int|null the issue's number, or null when the payload has no numeric `number`
     */
    private function dispatchIssue(Integration $integration, SyncSession $session, array $issue): ?int
    {
        $issueData = GitHubIssueData::from($issue);
        $updatedAt = self::parseTimestamp($issue['updated_at'] ?? null);
        $number = array_key_exists('number', $issue) && (is_int($issue['number']) || is_string($issue['number']))
            ? (string) $issue['number']
            : null;

        $session->dispatch(
            new GitHubIssueSynced($integration, $issueData),
            checkpointValue: $updatedAt?->toIso8601String(),
            externalId: $number,
        );

        return $number !== null && ctype_digit($number) ? (int) $number : null;
    }

    /**
     * Returns null for pull request comments, whose `issue_url` also points at `/issues/{number}`.
     *
     * @param  array<string, mixed>  $comment
     */
    private static function issueNumberOfComment(array $comment): ?int
    {
        $htmlUrl = $comment['html_url'] ?? null;
        if (is_string($htmlUrl) && str_contains($htmlUrl, '/pull/')) {
            return null;
        }

        $issueUrl = $comment['issue_url'] ?? null;
        if (! is_string($issueUrl) || preg_match('#/issues/(\d+)$#', $issueUrl, $matches) === 0) {
            return null;
        }

        $digits = $matches[1] ?? null;

        return is_string($digits) ? (int) $digits : null;
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function sensitiveRequestFields(): array
    {
        return [];
    }

    /**
     * @return list<string>
     */
    #[\Override]
    public function sensitiveResponseFields(): array
    {
        return [];
    }

    #[\Override]
    public function defaultSyncInterval(): int
    {
        return 5;
    }

    #[\Override]
    public function defaultRateLimit(): RateLimit
    {
        // GitHub's authenticated REST budget: 5,000 requests/hour, a fixed
        // window that resets at X-RateLimit-Reset.
        return RateLimit::perHour(5000);
    }

    #[\Override]
    public function healthCheck(Integration $integration): bool
    {
        $credentials = $integration->credentials;
        $metadata = $integration->metadata;

        if (! $credentials instanceof GitHubCredentials || ! $metadata instanceof GitHubMetadata) {
            return false;
        }

        try {
            $response = Http::withToken($credentials->token)
                ->withHeaders(['Accept' => 'application/vnd.github.v3+json'])
                ->connectTimeout(5)
                ->timeout(10)
                ->get("https://api.github.com/repos/{$metadata->owner}/{$metadata->repo}");

            return $response->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    #[\Override]
    public function authenticatedUser(Integration $integration): AuthenticatedUser
    {
        $payload = $this->makeClient($integration)->users()->authenticated();
        $user = GitHubUserData::from($payload);

        return new AuthenticatedUser(
            id: (string) $user->id,
            username: $user->login,
            name: $user->name,
            email: $user->email,
            raw: $payload,
        );
    }

    private static function parseTimestamp(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d\TH:i:sP', $value);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function makeClient(Integration $integration): GitHubClient
    {
        return new GitHubClient($integration);
    }

    private static function isRateLimitMessage(string $message): bool
    {
        $lower = mb_strtolower($message);

        return str_contains($lower, 'rate limit')
            || str_contains($lower, 'throttl')
            || str_contains($lower, 'abuse');
    }
}
