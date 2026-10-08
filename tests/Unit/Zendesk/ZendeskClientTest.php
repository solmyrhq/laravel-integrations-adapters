<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Zendesk;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Illuminate\Validation\ValidationException;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Adapters\Zendesk\Data\ZendeskCommentData;
use Integrations\Adapters\Zendesk\Data\ZendeskTicketData;
use Integrations\Adapters\Zendesk\Data\ZendeskUserData;
use Integrations\Adapters\Zendesk\ZendeskClient;
use Integrations\Adapters\Zendesk\ZendeskProvider;
use Integrations\Exceptions\SchemaDriftException;
use Integrations\Models\Integration;
use Integrations\Testing\CreatesIntegration;
use Zendesk\API\HttpClient as ZendeskAPI;

class ZendeskClientTest extends TestCase
{
    use CreatesIntegration;

    private function createIntegrationModel(): Integration
    {
        return $this->createIntegration(
            providerKey: 'zendesk',
            providerClass: ZendeskProvider::class,
            credentials: ['email' => 'test@acme.com', 'token' => 'fake-token'],
            metadata: ['subdomain' => 'acme'],
        );
    }

    private function createMockSdk(MockHandler $mockHandler): ZendeskAPI
    {
        $handlerStack = HandlerStack::create($mockHandler);
        $sdk = new ZendeskAPI('acme');
        $sdk->setAuth('basic', ['username' => 'test@acme.com', 'token' => 'fake-token']);
        $sdk->guzzle = new GuzzleClient(['handler' => $handlerStack]);

        return $sdk;
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeTicket(): array
    {
        return [
            'id' => 123,
            'url' => 'https://acme.zendesk.com/api/v2/tickets/123.json',
            'external_id' => null,
            'subject' => 'Test ticket',
            'raw_subject' => 'Test ticket',
            'description' => 'Test ticket description',
            'status' => 'open',
            'type' => null,
            'priority' => null,
            'custom_status_id' => 0,
            'requester_id' => 1,
            'submitter_id' => 1,
            'assignee_id' => null,
            'organization_id' => null,
            'group_id' => 1,
            'brand_id' => null,
            'forum_topic_id' => null,
            'problem_id' => null,
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'due_at' => null,
            'generated_timestamp' => 1735689600,
            'via' => ['channel' => 'web', 'source' => []],
            'custom_fields' => [],
            'has_incidents' => false,
            'is_public' => true,
            'allow_channelback' => false,
            'allow_attachments' => true,
            'from_messaging_channel' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeUser(): array
    {
        return [
            'id' => 456,
            'url' => 'https://acme.zendesk.com/api/v2/users/456.json',
            'name' => 'Test User',
            'email' => 'user@test.com',
            'external_id' => null,
            'active' => true,
            'suspended' => false,
            'verified' => true,
            'role' => 'end-user',
            'role_type' => null,
            'custom_role_id' => null,
            'moderator' => false,
            'ticket_restriction' => 'requested',
            'only_private_comments' => false,
            'restricted_agent' => true,
            'organization_id' => null,
            'default_group_id' => null,
            'phone' => null,
            'shared_phone_number' => null,
            'photo' => null,
            'time_zone' => 'UTC',
            'iana_time_zone' => 'Etc/UTC',
            'locale_id' => 1,
            'locale' => 'en-US',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
            'last_login_at' => null,
            'two_factor_auth_enabled' => null,
            'shared' => false,
            'shared_agent' => false,
            'report_csv' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeComment(int $id): array
    {
        return [
            'id' => $id,
            'type' => 'Comment',
            'author_id' => 1,
            'audit_id' => 1000 + $id,
            'body' => "Comment {$id}",
            'html_body' => "<p>Comment {$id}</p>",
            'plain_body' => "Comment {$id}",
            'public' => true,
            'attachments' => [],
            'via' => ['channel' => 'web', 'source' => []],
            'created_at' => '2026-01-01T00:00:00Z',
        ];
    }

    private function jsonResponse(mixed $data, int $status = 200): Response
    {
        $json = json_encode($data);

        return new Response($status, ['Content-Type' => 'application/json'], is_string($json) ? $json : '{}');
    }

    public function test_get_ticket_returns_ticket_data(): void
    {
        $mockHandler = new MockHandler([
            $this->jsonResponse(['ticket' => $this->fakeTicket()]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        $result = $client->tickets()->get(123);

        $this->assertInstanceOf(ZendeskTicketData::class, $result);
        $this->assertSame(123, $result->id);
        $this->assertSame('Test ticket', $result->subject);
    }

    public function test_get_user_returns_user_data(): void
    {
        $mockHandler = new MockHandler([
            $this->jsonResponse(['user' => $this->fakeUser()]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        $result = $client->users()->get(456);

        $this->assertInstanceOf(ZendeskUserData::class, $result);
        $this->assertSame(456, $result->id);
        $this->assertSame('Test User', $result->name);
    }

    public function test_get_ticket_comments_calls_callback_per_comment(): void
    {
        $mockHandler = new MockHandler([
            $this->jsonResponse([
                'comments' => [
                    [
                        'id' => 500,
                        'type' => 'Comment',
                        'audit_id' => 1001,
                        'body' => 'First comment',
                        'html_body' => '<p>First comment</p>',
                        'plain_body' => 'First comment',
                        'author_id' => 1,
                        'public' => true,
                        'attachments' => [],
                        'via' => ['channel' => 'web', 'source' => []],
                        'created_at' => '2026-01-01T00:00:00Z',
                    ],
                    [
                        'id' => 501,
                        'type' => 'Comment',
                        'audit_id' => 1002,
                        'body' => 'Second comment',
                        'html_body' => '<p>Second comment</p>',
                        'plain_body' => 'Second comment',
                        'author_id' => 2,
                        'public' => false,
                        'attachments' => [],
                        'via' => ['channel' => 'web', 'source' => []],
                        'created_at' => '2026-01-02T00:00:00Z',
                    ],
                ],
                'meta' => ['has_more' => false],
            ]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        $comments = [];
        $client->comments()->list(123, function ($comment) use (&$comments): void {
            $comments[] = $comment;
        });

        $this->assertCount(2, $comments);
    }

    public function test_comments_newer_than_returns_matching_comments(): void
    {
        $mockHandler = new MockHandler([
            // Search response: 2 tickets
            $this->jsonResponse([
                'results' => [$this->fakeTicket(), array_merge($this->fakeTicket(), ['id' => 456])],
                'next_page' => null,
            ]),
            // Comments for ticket 123: one old, one new
            $this->jsonResponse([
                'comments' => [$this->fakeComment(90), $this->fakeComment(110)],
                'meta' => ['has_more' => false],
            ]),
            // Comments for ticket 456: one old, one new
            $this->jsonResponse([
                'comments' => [$this->fakeComment(95), $this->fakeComment(105)],
                'meta' => ['has_more' => false],
            ]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        /** @var list<array{ZendeskCommentData, int}> $results */
        $results = [];
        $client->comments()->newerThan(100, function (ZendeskCommentData $comment, int $ticketId) use (&$results): void {
            $results[] = [$comment, $ticketId];
        });

        $this->assertCount(2, $results);
        $this->assertSame(110, $results[0][0]->id);
        $this->assertSame(123, $results[0][1]);
        $this->assertSame(105, $results[1][0]->id);
        $this->assertSame(456, $results[1][1]);
    }

    public function test_comments_newer_than_handles_no_search_results(): void
    {
        $mockHandler = new MockHandler([
            $this->jsonResponse([
                'results' => [],
                'next_page' => null,
            ]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        $called = false;
        $client->comments()->newerThan(100, function () use (&$called): void {
            $called = true;
        });

        $this->assertFalse($called);
    }

    public function test_comments_newer_than_paginates_search_results(): void
    {
        $mockHandler = new MockHandler([
            // Search page 1: 1 ticket, has next page
            $this->jsonResponse([
                'results' => [$this->fakeTicket()],
                'next_page' => 'https://acme.zendesk.com/api/v2/search.json?page=2',
            ]),
            // Search page 2: 1 ticket, no next page
            $this->jsonResponse([
                'results' => [array_merge($this->fakeTicket(), ['id' => 456])],
                'next_page' => null,
            ]),
            // Comments for ticket 123
            $this->jsonResponse([
                'comments' => [$this->fakeComment(200)],
                'meta' => ['has_more' => false],
            ]),
            // Comments for ticket 456
            $this->jsonResponse([
                'comments' => [$this->fakeComment(201)],
                'meta' => ['has_more' => false],
            ]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        /** @var list<array{ZendeskCommentData, int}> $results */
        $results = [];
        $client->comments()->newerThan(100, function (ZendeskCommentData $comment, int $ticketId) use (&$results): void {
            $results[] = [$comment, $ticketId];
        });

        $this->assertCount(2, $results);
        $this->assertSame(200, $results[0][0]->id);
        $this->assertSame(123, $results[0][1]);
        $this->assertSame(201, $results[1][0]->id);
        $this->assertSame(456, $results[1][1]);
    }

    public function test_since_hydrates_incremental_tickets_through_real_decode_path(): void
    {
        $mockHandler = new MockHandler([
            $this->jsonResponse([
                'tickets' => [
                    array_merge($this->fakeTicket(), ['id' => 1001, 'requester_id' => 456]),
                    array_merge($this->fakeTicket(), ['id' => 1002, 'requester_id' => 456]),
                ],
                'users' => [$this->fakeUser()],
                'next_page' => null,
                'end_of_stream' => true,
                'count' => 2,
            ]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        /** @var list<array{ZendeskTicketData, ?ZendeskUserData}> $received */
        $received = [];
        $client->tickets()->since(
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            function (ZendeskTicketData $ticket, ?ZendeskUserData $user) use (&$received): void {
                $received[] = [$ticket, $user];
            },
        );

        $this->assertCount(2, $received);
        $this->assertSame(1001, $received[0][0]->id);
        $this->assertSame(1002, $received[1][0]->id);
        $this->assertInstanceOf(ZendeskUserData::class, $received[0][1]);
        $this->assertSame(456, $received[0][1]->id);
    }

    public function test_since_hydrates_incremental_tickets_with_an_empty_subject(): void
    {
        $mockHandler = new MockHandler([
            $this->jsonResponse([
                'tickets' => [
                    array_merge($this->fakeTicket(), ['id' => 1001, 'subject' => '', 'raw_subject' => '']),
                ],
                'users' => [],
                'next_page' => null,
                'end_of_stream' => true,
                'count' => 1,
            ]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        /** @var list<ZendeskTicketData> $received */
        $received = [];
        $client->tickets()->since(
            new \DateTimeImmutable('2026-01-01T00:00:00Z'),
            function (ZendeskTicketData $ticket) use (&$received): void {
                $received[] = $ticket;
            },
        );

        $this->assertCount(1, $received);
        $this->assertSame('', $received[0]->subject);
        $this->assertSame('', $received[0]->raw_subject);
    }

    public function test_since_rejects_an_incremental_ticket_without_a_subject(): void
    {
        $ticket = $this->fakeTicket();
        unset($ticket['subject']);

        $mockHandler = new MockHandler([
            $this->jsonResponse([
                'tickets' => [$ticket],
                'users' => [],
                'next_page' => null,
                'end_of_stream' => true,
                'count' => 1,
            ]),
        ]);

        $integration = $this->createIntegrationModel();
        $sdk = $this->createMockSdk($mockHandler);
        $client = new ZendeskClient($integration, $sdk);

        $this->expectException(SchemaDriftException::class);
        $this->expectExceptionMessage('tickets.0.subject');

        $client->tickets()->since(new \DateTimeImmutable('2026-01-01T00:00:00Z'), function (): void {});
    }

    public function test_deferred_validation_throws_on_first_use(): void
    {
        config(['app.debug' => true]);

        $integration = $this->createIntegration(
            providerKey: 'zendesk',
            providerClass: ZendeskProvider::class,
            credentials: ['invalid' => 'data'],
            metadata: ['subdomain' => 'acme'],
        );

        $client = new ZendeskClient($integration);

        $this->expectException(ValidationException::class);

        $client->tickets()->get(1);
    }
}
