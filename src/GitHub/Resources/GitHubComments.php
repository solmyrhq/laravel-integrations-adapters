<?php

declare(strict_types=1);

namespace Integrations\Adapters\GitHub\Resources;

use Github\HttpClient\Message\ResponseMediator;
use Integrations\Adapters\GitHub\Data\GitHubCommentData;
use Integrations\Adapters\GitHub\GitHubResource;
use Integrations\RequestContext;
use RuntimeException;

class GitHubComments extends GitHubResource
{
    /**
     * Get all comments for an issue.
     *
     * @param  callable(array<string, mixed>): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    public function list(int $issueNumber, callable $callback): void
    {
        $page = 1;
        $perPage = 100;

        do {
            /** @var list<array<string, mixed>> $comments */
            $comments = $this->integration
                ->at("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}/comments?page={$page}")
                ->withData(['issue_number' => $issueNumber, 'page' => $page])
                ->get(function (RequestContext $ctx) use ($issueNumber, $perPage, $page): array {
                    $result = $this->getIssueApi()->comments()
                        ->configure('full')
                        ->all(
                            $this->owner(),
                            $this->repo(),
                            $issueNumber,
                            [
                                'per_page' => $perPage,
                                'page' => $page,
                            ]
                        );
                    $this->reportGitHubMetadata($ctx);

                    return $result;
                });

            if ($comments === []) {
                break;
            }

            foreach ($comments as $comment) {
                $callback($comment);
            }

            $page++;
        } while (count($comments) === $perPage);
    }

    /**
     * Get the repository's issue and pull request comments updated since `$since`, oldest first.
     *
     * @param  callable(array<string, mixed>): void  $callback
     *
     * @param-immediately-invoked-callable $callback
     */
    public function since(\DateTimeInterface $since, callable $callback): void
    {
        $page = 1;
        $perPage = 100;

        do {
            $comments = self::commentList($this->integration
                ->at("repos/{$this->owner()}/{$this->repo()}/issues/comments?page={$page}")
                ->withData(['since' => $since->format('c'), 'page' => $page])
                ->get(function (RequestContext $ctx) use ($since, $perPage, $page): mixed {
                    // The SDK's comments()->all() requires an issue number; it has no repository-wide listing.
                    $query = http_build_query([
                        'since' => $since->format('c'),
                        'sort' => 'updated',
                        'direction' => 'asc',
                        'per_page' => $perPage,
                        'page' => $page,
                    ]);
                    $response = $this->sdk()->getHttpClient()->get(
                        "/repos/{$this->owner()}/{$this->repo()}/issues/comments?{$query}",
                        ['Accept' => 'application/vnd.github.full+json'],
                    );
                    $this->reportGitHubMetadata($ctx);

                    return ResponseMediator::getContent($response);
                }));

            if ($comments === []) {
                break;
            }

            foreach ($comments as $comment) {
                $callback($comment);
            }

            $page++;
        } while (count($comments) === $perPage);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function commentList(mixed $content): array
    {
        if (! is_array($content) || ! array_is_list($content)) {
            throw new RuntimeException('Expected a list of comments from GitHub, got '.get_debug_type($content).'.');
        }

        $comments = [];
        foreach ($content as $comment) {
            if (! is_array($comment)) {
                throw new RuntimeException('Expected each GitHub comment to be an object, got '.get_debug_type($comment).'.');
            }

            $fields = [];
            foreach ($comment as $key => $value) {
                if (! is_string($key)) {
                    throw new RuntimeException('Expected each GitHub comment to be an object, got a list.');
                }

                $fields[$key] = $value;
            }

            $comments[] = $fields;
        }

        return $comments;
    }

    public function add(int $issueNumber, string $comment, ?string $idempotencyKey = null): ?GitHubCommentData
    {
        return $this->executeWithErrorHandling(function () use ($issueNumber, $comment, $idempotencyKey): GitHubCommentData {
            /** @var array<string, mixed> $response */
            $response = $this->integration
                ->at("repos/{$this->owner()}/{$this->repo()}/issues/{$issueNumber}/comments")
                ->withData(['body' => $comment])
                ->withIdempotencyKey($idempotencyKey)
                ->post(function (RequestContext $ctx) use ($issueNumber, $comment): array {
                    $result = $this->getIssueApi()->comments()->create($this->owner(), $this->repo(), $issueNumber, ['body' => $comment]);
                    $this->reportGitHubMetadata($ctx);

                    return $result;
                });

            return GitHubCommentData::from($response);
        });
    }
}
