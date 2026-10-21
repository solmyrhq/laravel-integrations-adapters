<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\GitHub;

use Github\AuthMethod;
use Github\Client as GithubSdkClient;
use Github\HttpClient\Builder;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockHttpClient;
use Integrations\Adapters\GitHub\Events\GitHubIssueSynced;
use Integrations\Adapters\GitHub\GitHubClient;
use Integrations\Adapters\GitHub\GitHubProvider;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Models\Integration;
use Integrations\Sync\SyncItemEvent;
use Integrations\Testing\CreatesIntegration;
use Integrations\Testing\FakeSyncSession;

class GitHubProviderSyncTest extends TestCase
{
    use CreatesIntegration;

    public function test_sync_incremental_dispatches_each_issue_to_the_session(): void
    {
        $integration = $this->createIntegrationModel();
        $provider = $this->makeProviderWithMockedSdk(new MockHttpClient, [
            [
                $this->fakeIssue(['id' => 1, 'number' => 1, 'updated_at' => '2026-01-01T10:00:00Z']),
                $this->fakeIssue(['id' => 2, 'number' => 2, 'updated_at' => '2026-01-01T12:00:00Z']),
            ],
        ]);

        $session = new FakeSyncSession($integration);
        $provider->syncIncremental($integration, $session);

        $session->assertDispatchedCount(2);
        $session->assertDispatched(
            GitHubIssueSynced::class,
            fn (SyncItemEvent $event, mixed $checkpoint, ?string $externalId): bool => $event instanceof GitHubIssueSynced
                && $event->issue->number === 1
                && $checkpoint === '2026-01-01T10:00:00+00:00'
                && $externalId === '1',
        );
        $session->assertDispatched(
            GitHubIssueSynced::class,
            fn (SyncItemEvent $event, mixed $checkpoint, ?string $externalId): bool => $event instanceof GitHubIssueSynced
                && $event->issue->number === 2
                && $checkpoint === '2026-01-01T12:00:00+00:00'
                && $externalId === '2',
        );
    }

    public function test_sync_incremental_scopes_the_fetch_by_the_session_cursor(): void
    {
        $integration = $this->createIntegrationModel();
        $integration->updateSyncCursor('2026-06-01T12:00:00+00:00');

        $mockHttp = new MockHttpClient;
        $provider = $this->makeProviderWithMockedSdk($mockHttp, [[], []]);

        $session = new FakeSyncSession($integration);
        $provider->syncIncremental($integration, $session);

        $session->assertNothingDispatched();

        $requests = $mockHttp->getRequests();
        $this->assertCount(2, $requests);
        $this->assertSame('/repos/acme/widgets/issues', $requests[0]->getUri()->getPath());
        $this->assertStringContainsString('2026-06-01T11%3A00%3A00', $requests[0]->getUri()->getQuery());
        $this->assertSame('/repos/acme/widgets/issues/comments', $requests[1]->getUri()->getPath());
        $this->assertStringContainsString('2026-06-01T11%3A00%3A00', $requests[1]->getUri()->getQuery());
    }

    public function test_sync_incremental_syncs_an_issue_that_only_the_comments_feed_returned(): void
    {
        $integration = $this->createIntegrationModel();
        $integration->updateSyncCursor('2026-06-01T12:00:00+00:00');

        $mockHttp = new MockHttpClient;
        $provider = $this->makeProviderWithMockedSdk($mockHttp, [
            [$this->fakeIssue(['id' => 1, 'number' => 1, 'updated_at' => '2026-06-01T11:30:00Z'])],
            [$this->fakeComment(1), $this->fakeComment(7)],
            $this->fakeIssue(['id' => 7, 'number' => 7, 'updated_at' => '2026-06-01T11:45:00Z']),
        ]);

        $session = new FakeSyncSession($integration);
        $provider->syncIncremental($integration, $session);

        $session->assertDispatchedCount(2);
        $session->assertDispatched(
            GitHubIssueSynced::class,
            fn (SyncItemEvent $event, mixed $checkpoint, ?string $externalId): bool => $event instanceof GitHubIssueSynced
                && $event->issue->number === 7
                && $checkpoint === '2026-06-01T11:45:00+00:00'
                && $externalId === '7',
        );

        $requests = $mockHttp->getRequests();
        $this->assertCount(3, $requests);
        $this->assertSame('/repos/acme/widgets/issues/7', $requests[2]->getUri()->getPath());
    }

    public function test_sync_incremental_does_not_fetch_an_issue_that_the_issues_list_already_returned(): void
    {
        $integration = $this->createIntegrationModel();
        $integration->updateSyncCursor('2026-06-01T12:00:00+00:00');

        $mockHttp = new MockHttpClient;
        $provider = $this->makeProviderWithMockedSdk($mockHttp, [
            [$this->fakeIssue(['id' => 1, 'number' => 1, 'updated_at' => '2026-06-01T11:30:00Z'])],
            [$this->fakeComment(1), $this->fakeComment(1, ['id' => 902])],
        ]);

        $session = new FakeSyncSession($integration);
        $provider->syncIncremental($integration, $session);

        $session->assertDispatchedCount(1);
        $this->assertCount(2, $mockHttp->getRequests());
    }

    public function test_sync_incremental_ignores_pull_request_comments_in_the_comments_feed(): void
    {
        $integration = $this->createIntegrationModel();
        $integration->updateSyncCursor('2026-06-01T12:00:00+00:00');

        $mockHttp = new MockHttpClient;
        $provider = $this->makeProviderWithMockedSdk($mockHttp, [
            [],
            [$this->fakeComment(9, ['html_url' => 'https://github.com/acme/widgets/pull/9#issuecomment-901'])],
        ]);

        $session = new FakeSyncSession($integration);
        $provider->syncIncremental($integration, $session);

        $session->assertNothingDispatched();
        $this->assertCount(2, $mockHttp->getRequests());
    }

    public function test_sync_incremental_skips_an_issue_from_the_comments_feed_that_turns_out_to_be_a_pull_request(): void
    {
        $integration = $this->createIntegrationModel();
        $integration->updateSyncCursor('2026-06-01T12:00:00+00:00');

        $mockHttp = new MockHttpClient;
        $provider = $this->makeProviderWithMockedSdk($mockHttp, [
            [],
            [$this->fakeComment(9)],
            $this->fakeIssue(['id' => 9, 'number' => 9, 'pull_request' => ['url' => 'https://api.github.com/repos/acme/widgets/pulls/9']]),
        ]);

        $session = new FakeSyncSession($integration);
        $provider->syncIncremental($integration, $session);

        $session->assertNothingDispatched();
    }

    public function test_a_first_sync_without_a_cursor_does_not_read_the_comments_feed(): void
    {
        $integration = $this->createIntegrationModel();

        $mockHttp = new MockHttpClient;
        $provider = $this->makeProviderWithMockedSdk($mockHttp, [[]]);

        $session = new FakeSyncSession($integration);
        $provider->syncIncremental($integration, $session);

        $requests = $mockHttp->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('/repos/acme/widgets/issues', $requests[0]->getUri()->getPath());
    }

    public function test_reduce_checkpoints_returns_the_latest_checkpoint(): void
    {
        $provider = new GitHubProvider;

        $this->assertSame(
            '2026-01-01T12:00:00+00:00',
            $provider->reduceCheckpoints([
                '2026-01-01T10:00:00+00:00',
                '2026-01-01T12:00:00+00:00',
                '2026-01-01T11:00:00+00:00',
            ]),
        );
        $this->assertNull($provider->reduceCheckpoints([]));
    }

    private function createIntegrationModel(): Integration
    {
        return $this->createIntegration(
            providerKey: 'github',
            providerClass: GitHubProvider::class,
            credentials: ['token' => 'ghp_fake123'],
            metadata: ['owner' => 'acme', 'repo' => 'widgets'],
        );
    }

    /**
     * @param  list<array<array-key, mixed>>  $pages  one element per response returned by the SDK, in request order
     */
    private function makeProviderWithMockedSdk(MockHttpClient $mockHttp, array $pages): GitHubProvider
    {
        foreach ($pages as $page) {
            $mockHttp->addResponse($this->jsonResponse($page));
        }

        $builder = new Builder($mockHttp);
        $sdk = new GithubSdkClient($builder);
        $sdk->authenticate('ghp_fake123', null, AuthMethod::ACCESS_TOKEN);

        return new class($sdk) extends GitHubProvider
        {
            public function __construct(private readonly GithubSdkClient $injectedSdk) {}

            #[\Override]
            protected function makeClient(Integration $integration): GitHubClient
            {
                return new GitHubClient($integration, $this->injectedSdk);
            }
        };
    }

    private function jsonResponse(mixed $data, int $status = 200): Response
    {
        $json = json_encode($data);

        return new Response($status, ['Content-Type' => 'application/json'], is_string($json) ? $json : '{}');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fakeComment(int $issueNumber, array $overrides = []): array
    {
        return array_merge([
            'id' => 900 + $issueNumber,
            'node_id' => 'IC_kwDOtest',
            'body' => 'A comment',
            'html_url' => "https://github.com/acme/widgets/issues/{$issueNumber}#issuecomment-".(900 + $issueNumber),
            'issue_url' => "https://api.github.com/repos/acme/widgets/issues/{$issueNumber}",
            'created_at' => '2026-06-01T11:40:00Z',
            'updated_at' => '2026-06-01T11:40:00Z',
            'user' => [
                'id' => 2,
                'login' => 'commenter',
                'node_id' => 'MDQ6VXNlcjI=',
                'avatar_url' => 'https://example.com/avatar.png',
                'url' => 'https://api.github.com/users/commenter',
                'html_url' => 'https://github.com/commenter',
                'type' => 'User',
                'site_admin' => false,
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function fakeIssue(array $overrides = []): array
    {
        return array_merge([
            'id' => 1,
            'number' => 42,
            'node_id' => 'MDU6SXNzdWUx',
            'title' => 'Test issue',
            'body' => 'Body text',
            'body_html' => '<p>Body text</p>',
            'state' => 'open',
            'url' => 'https://api.github.com/repos/acme/widgets/issues/42',
            'html_url' => 'https://github.com/acme/widgets/issues/42',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'user' => [
                'id' => 1,
                'login' => 'testuser',
                'node_id' => 'MDQ6VXNlcjE=',
                'avatar_url' => 'https://example.com/avatar.png',
                'url' => 'https://api.github.com/users/testuser',
                'html_url' => 'https://github.com/testuser',
                'type' => 'User',
                'site_admin' => false,
            ],
        ], $overrides);
    }
}
