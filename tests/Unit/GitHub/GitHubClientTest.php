<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\GitHub;

use Github\AuthMethod;
use Github\Client as GithubSdkClient;
use Github\HttpClient\Builder;
use GuzzleHttp\Psr7\Response;
use Http\Mock\Client as MockHttpClient;
use Integrations\Adapters\GitHub\Data\GitHubCommentData;
use Integrations\Adapters\GitHub\Data\GitHubIssueData;
use Integrations\Adapters\GitHub\GitHubClient;
use Integrations\Adapters\GitHub\GitHubProvider;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Testing\CreatesIntegration;

class GitHubClientTest extends TestCase
{
    use CreatesIntegration;

    private function createClient(MockHttpClient $mockHttp): GitHubClient
    {
        $integration = $this->createIntegration(
            providerKey: 'github',
            providerClass: GitHubProvider::class,
            credentials: ['token' => 'ghp_fake123'],
            metadata: ['owner' => 'acme', 'repo' => 'widgets'],
        );

        $builder = new Builder($mockHttp);
        $sdk = new GithubSdkClient($builder);
        $sdk->authenticate('ghp_fake123', null, AuthMethod::ACCESS_TOKEN);

        return new GitHubClient($integration, $sdk);
    }

    private function jsonResponse(mixed $data, int $status = 200): Response
    {
        $json = json_encode($data);

        return new Response($status, ['Content-Type' => 'application/json'], is_string($json) ? $json : '{}');
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeUser(): array
    {
        return [
            'id' => 1,
            'login' => 'testuser',
            'node_id' => 'MDQ6VXNlcjE=',
            'avatar_url' => 'https://example.com/avatar.png',
            'url' => 'https://api.github.com/users/testuser',
            'html_url' => 'https://github.com/testuser',
            'type' => 'User',
            'site_admin' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeIssue(string $state = 'open'): array
    {
        return [
            'id' => 1,
            'number' => 42,
            'node_id' => 'MDU6SXNzdWUx',
            'title' => 'Test issue',
            'body' => 'Body text',
            'body_html' => '<p>Body text</p>',
            'state' => $state,
            'url' => 'https://api.github.com/repos/acme/widgets/issues/42',
            'html_url' => 'https://github.com/acme/widgets/issues/42',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'user' => $this->fakeUser(),
        ];
    }

    public function test_create_issue_returns_issue_data(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse($this->fakeIssue(), 201));

        $client = $this->createClient($mockHttp);
        $result = $client->issues()->create('Test issue', 'Body text');

        $this->assertInstanceOf(GitHubIssueData::class, $result);
        $this->assertSame(42, $result->number);
        $this->assertSame('Test issue', $result->title);
    }

    public function test_get_issue_returns_array(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse([
            'id' => 1,
            'number' => 42,
            'title' => 'Test issue',
            'state' => 'open',
        ]));

        $client = $this->createClient($mockHttp);
        $result = $client->issues()->get(42);

        $this->assertIsArray($result);
        $this->assertSame(42, $result['number']);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generateIssues(int $count, int $startId = 1): array
    {
        return array_map(fn (int $i) => [
            'id' => $startId + $i,
            'number' => $startId + $i,
            'title' => 'Issue '.($startId + $i),
            'state' => 'open',
        ], range(0, $count - 1));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function generateComments(int $count, int $startId = 100): array
    {
        return array_map(fn (int $i) => [
            'id' => $startId + $i,
            'body' => 'Comment '.($startId + $i),
            'body_html' => '<p>Comment '.($startId + $i).'</p>',
            'user' => ['id' => 1, 'login' => 'user1'],
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ], range(0, $count - 1));
    }

    public function test_get_issues_since_paginates_and_calls_callback(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse($this->generateIssues(100)));
        $mockHttp->addResponse($this->jsonResponse($this->generateIssues(3, 101)));

        $client = $this->createClient($mockHttp);
        $issues = [];
        $client->issues()->since(new \DateTimeImmutable('2026-01-01'), function (array $issue) use (&$issues): void {
            $issues[] = $issue;
        });

        $this->assertCount(103, $issues);
        $this->assertSame(1, $issues[0]['number']);
        $this->assertSame(103, $issues[102]['number']);
    }

    public function test_get_issues_since_skips_pull_requests(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse([
            ['id' => 1, 'number' => 1, 'title' => 'Issue', 'state' => 'open'],
            ['id' => 2, 'number' => 2, 'title' => 'PR', 'state' => 'open', 'pull_request' => ['url' => 'https://...']],
        ]));

        $client = $this->createClient($mockHttp);
        $issues = [];
        $client->issues()->since(new \DateTimeImmutable('2026-01-01'), function (array $issue) use (&$issues): void {
            $issues[] = $issue;
        });

        $this->assertCount(1, $issues);
        $this->assertSame('Issue', $issues[0]['title']);
    }

    public function test_get_issue_comments_paginates_and_calls_callback(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse($this->generateComments(100)));
        $mockHttp->addResponse($this->jsonResponse($this->generateComments(2, 200)));

        $client = $this->createClient($mockHttp);
        $comments = [];
        $client->comments()->list(42, function (array $comment) use (&$comments): void {
            $comments[] = $comment;
        });

        $this->assertCount(102, $comments);
        $this->assertSame(100, $comments[0]['id']);
        $this->assertSame(201, $comments[101]['id']);
    }

    public function test_get_repository_comments_since_paginates_and_calls_callback(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse($this->generateComments(100)));
        $mockHttp->addResponse($this->jsonResponse($this->generateComments(2, 200)));

        $client = $this->createClient($mockHttp);
        $comments = [];
        $client->comments()->since(new \DateTimeImmutable('2026-06-01T11:00:00+00:00'), function (array $comment) use (&$comments): void {
            $comments[] = $comment;
        });

        $this->assertCount(102, $comments);
        $this->assertSame(100, $comments[0]['id']);
        $this->assertSame(201, $comments[101]['id']);

        $requests = $mockHttp->getRequests();
        $this->assertCount(2, $requests);
        $this->assertSame('/repos/acme/widgets/issues/comments', $requests[0]->getUri()->getPath());
        $this->assertStringContainsString('since=2026-06-01T11%3A00%3A00%2B00%3A00', $requests[0]->getUri()->getQuery());
        $this->assertStringContainsString('page=2', $requests[1]->getUri()->getQuery());
        $this->assertSame('application/vnd.github.full+json', $requests[0]->getHeaderLine('Accept'));
    }

    public function test_get_repository_comments_since_rejects_a_response_that_is_not_a_list(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse(['message' => 'Not a list']));

        $client = $this->createClient($mockHttp);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Expected a list of comments from GitHub');

        $client->comments()->since(new \DateTimeImmutable('2026-06-01'), function (array $comment): void {});
    }

    public function test_close_issue_returns_issue_data(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse($this->fakeIssue('closed')));

        $client = $this->createClient($mockHttp);
        $result = $client->issues()->close(42);

        $this->assertInstanceOf(GitHubIssueData::class, $result);
        $this->assertSame('closed', $result->state);
    }

    public function test_update_issue_sends_patch_and_returns_issue_data(): void
    {
        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse(array_merge($this->fakeIssue(), ['body' => 'Updated body'])));

        $client = $this->createClient($mockHttp);
        $result = $client->issues()->update(42, body: 'Updated body');

        $this->assertInstanceOf(GitHubIssueData::class, $result);
        $this->assertSame('Updated body', $result->body);

        $requests = $mockHttp->getRequests();
        $this->assertCount(1, $requests);
        $this->assertSame('PATCH', $requests[0]->getMethod());
        $this->assertStringContainsString('/repos/acme/widgets/issues/42', (string) $requests[0]->getUri());
        $this->assertStringContainsString('Updated body', (string) $requests[0]->getBody());
    }

    public function test_update_issue_without_title_or_body_throws(): void
    {
        $client = $this->createClient(new MockHttpClient);

        $this->expectException(\InvalidArgumentException::class);

        $client->issues()->update(42);
    }

    public function test_add_comment_returns_comment_data(): void
    {
        config(['app.debug' => true]);

        $mockHttp = new MockHttpClient;
        $mockHttp->addResponse($this->jsonResponse([
            'id' => 200,
            'node_id' => 'MDEyOklzc3VlQ29tbWVudDIwMA==',
            'body' => 'New comment',
            'body_html' => '<p>New comment</p>',
            'url' => 'https://api.github.com/repos/acme/widgets/issues/comments/200',
            'html_url' => 'https://github.com/acme/widgets/issues/42#issuecomment-200',
            'issue_url' => 'https://api.github.com/repos/acme/widgets/issues/42',
            'user' => $this->fakeUser(),
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ]));

        $client = $this->createClient($mockHttp);
        $result = $client->comments()->add(42, 'New comment');

        $this->assertInstanceOf(GitHubCommentData::class, $result);
        $this->assertSame('New comment', $result->body);
    }
}
