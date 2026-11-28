<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Postmark;

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request as GuzzleRequest;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Integrations\Adapters\Postmark\Data\PostmarkBounceWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkClickWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkDeliveryWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkInboundWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkOpenWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkSpamComplaintWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkSubscriptionChangeWebhookData;
use Integrations\Adapters\Postmark\Enums\PostmarkBounceType;
use Integrations\Adapters\Postmark\Events\PostmarkBounceReceived;
use Integrations\Adapters\Postmark\Events\PostmarkClickReceived;
use Integrations\Adapters\Postmark\Events\PostmarkDeliveryReceived;
use Integrations\Adapters\Postmark\Events\PostmarkInboundReceived;
use Integrations\Adapters\Postmark\Events\PostmarkOpenReceived;
use Integrations\Adapters\Postmark\Events\PostmarkSpamComplaintReceived;
use Integrations\Adapters\Postmark\Events\PostmarkSubscriptionChangeReceived;
use Integrations\Adapters\Postmark\Events\PostmarkWebhookReceived;
use Integrations\Adapters\Postmark\PostmarkClient;
use Integrations\Adapters\Postmark\PostmarkCredentials;
use Integrations\Adapters\Postmark\PostmarkMetadata;
use Integrations\Adapters\Postmark\PostmarkProvider;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Contracts\ClassifiesFailures;
use Integrations\Contracts\HandlesWebhooks;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\IdentifiesAuthenticatedUser;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Enums\FailureClass;
use Integrations\Models\Integration;
use Integrations\Testing\CreatesIntegration;
use Postmark\PostmarkClient as PostmarkSdkClient;
use RuntimeException;

class PostmarkProviderTest extends TestCase
{
    use CreatesIntegration;

    public function test_implements_required_contracts(): void
    {
        $provider = new PostmarkProvider;

        $this->assertInstanceOf(IntegrationProvider::class, $provider);
        $this->assertInstanceOf(HandlesWebhooks::class, $provider);
        $this->assertInstanceOf(HasHealthCheck::class, $provider);
        $this->assertInstanceOf(ClassifiesFailures::class, $provider);
        $this->assertInstanceOf(IdentifiesAuthenticatedUser::class, $provider);
    }

    public function test_authenticated_user_maps_the_postmark_server(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-abc'],
        );

        $json = (string) json_encode([
            'ID' => 12345,
            'Name' => 'Transactional',
            'Color' => 'blue',
            'ServerLink' => 'https://postmarkapp.com/servers/12345/streams',
        ]);

        $sdk = new PostmarkSdkClient('srv-abc');
        $sdk->setClient(new GuzzleClient(['handler' => HandlerStack::create(new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], $json),
        ]))]));

        $provider = new class($sdk) extends PostmarkProvider
        {
            public function __construct(private readonly PostmarkSdkClient $injectedSdk) {}

            #[\Override]
            protected function makeClient(Integration $integration): PostmarkClient
            {
                return new PostmarkClient($integration, $this->injectedSdk);
            }
        };

        $user = $provider->authenticatedUser($integration);

        $this->assertSame('12345', $user->id);
        $this->assertNull($user->username);
        $this->assertSame('Transactional', $user->name);
        $this->assertNull($user->email);
        $this->assertSame(12345, $user->raw['ID']);
    }

    public function test_classify_failure(): void
    {
        $provider = new PostmarkProvider;

        // A bare connection error has no status, so the provider maps it to an
        // upstream fault. Postmark's own exception exposes getHttpStatusCode(),
        // which core's duck-typing reads, so status-bearing errors defer to the
        // default classifier (return null).
        $this->assertSame(FailureClass::Upstream, $provider->classifyFailure(new ConnectException('refused', new GuzzleRequest('GET', 'https://api.postmarkapp.com'))));
        $this->assertNull($provider->classifyFailure(new RuntimeException('mystery')));
    }

    public function test_name(): void
    {
        $this->assertSame('Postmark', (new PostmarkProvider)->name());
    }

    public function test_credential_rules_require_server_token(): void
    {
        $rules = (new PostmarkProvider)->credentialRules();

        $this->assertArrayHasKey('server_token', $rules);
        $this->assertArrayHasKey('webhook_username', $rules);
        $this->assertArrayHasKey('webhook_password', $rules);
        $this->assertArrayHasKey('account_token', $rules);

        $serverTokenRule = $rules['server_token'];
        $this->assertIsArray($serverTokenRule);
        $this->assertContains('required', $serverTokenRule);
    }

    public function test_credential_rules_require_webhook_credentials_as_a_pair(): void
    {
        $rules = (new PostmarkProvider)->credentialRules();

        $usernameRule = $rules['webhook_username'];
        $passwordRule = $rules['webhook_password'];

        $this->assertIsArray($usernameRule);
        $this->assertIsArray($passwordRule);

        $this->assertContains('required_with:webhook_password', $usernameRule);
        $this->assertContains('required_with:webhook_username', $passwordRule);
    }

    public function test_metadata_rules_treat_message_stream_as_optional_but_non_null(): void
    {
        $rules = (new PostmarkProvider)->metadataRules();

        $this->assertArrayHasKey('message_stream', $rules);
        $this->assertArrayHasKey('server_name', $rules);

        // The DTO holds `string $message_stream = 'outbound'` so the field
        // must be either absent (default kicks in) or a string. Explicit
        // null would fail hydration, so it must not be allowed by the rule.
        $streamRule = $rules['message_stream'];
        $this->assertIsArray($streamRule);
        $this->assertContains('string', $streamRule);
        $this->assertNotContains('nullable', $streamRule);
    }

    public function test_data_classes(): void
    {
        $provider = new PostmarkProvider;

        $this->assertSame(PostmarkCredentials::class, $provider->credentialDataClass());
        $this->assertSame(PostmarkMetadata::class, $provider->metadataDataClass());
    }

    public function test_creates_integration_with_typed_credentials_and_metadata(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: [
                'server_token' => 'srv-abc',
                'webhook_username' => 'hookuser',
                'webhook_password' => 'hookpass',
            ],
            metadata: ['message_stream' => 'broadcasts', 'server_name' => 'Marketing'],
        );

        $this->assertInstanceOf(PostmarkCredentials::class, $integration->credentials);
        $this->assertSame('srv-abc', $integration->credentials->server_token);
        $this->assertSame('hookuser', $integration->credentials->webhook_username);
        $this->assertSame('hookpass', $integration->credentials->webhook_password);
        $this->assertNull($integration->credentials->account_token);

        $this->assertInstanceOf(PostmarkMetadata::class, $integration->metadata);
        $this->assertSame('broadcasts', $integration->metadata->message_stream);
        $this->assertSame('Marketing', $integration->metadata->server_name);
    }

    public function test_metadata_defaults_to_outbound_stream_when_empty(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-abc'],
        );

        // Default metadata is null when not provided; the PostmarkMetadata
        // default kicks in only when the integration is constructed with
        // an empty array. Verify the explicit construction case.
        $defaults = PostmarkMetadata::from([]);
        $this->assertSame('outbound', $defaults->message_stream);

        // And confirm the integration row itself still loads cleanly.
        $this->assertInstanceOf(Integration::class, $integration);
    }

    public function test_webhook_handlers_is_empty(): void
    {
        $this->assertSame([], (new PostmarkProvider)->webhookHandlers());
    }

    public function test_webhook_delivery_id_is_null_so_core_falls_back_to_payload_hash(): void
    {
        $request = Request::create('/webhook', 'POST', content: (string) json_encode([
            'RecordType' => 'Open',
            'MessageID' => 'msg-1',
        ]));

        $this->assertNull((new PostmarkProvider)->webhookDeliveryId($request));
    }

    public function test_resolve_webhook_event_reads_record_type_from_payload(): void
    {
        $request = Request::create('/webhook', 'POST', content: (string) json_encode([
            'RecordType' => 'Bounce',
            'MessageID' => 'msg-1',
        ]));

        $this->assertSame('Bounce', (new PostmarkProvider)->resolveWebhookEvent($request));
    }

    public function test_resolve_webhook_event_returns_null_when_record_type_is_missing(): void
    {
        $request = Request::create('/webhook', 'POST', content: (string) json_encode(['MessageID' => 'msg-1']));

        $this->assertNull((new PostmarkProvider)->resolveWebhookEvent($request));
    }

    public function test_resolve_webhook_event_returns_null_for_garbage_payload(): void
    {
        $request = Request::create('/webhook', 'POST', content: 'not-json');

        $this->assertNull((new PostmarkProvider)->resolveWebhookEvent($request));
    }

    public function test_resolve_webhook_event_returns_null_for_a_json_list(): void
    {
        $request = Request::create('/webhook', 'POST', content: (string) json_encode([
            ['RecordType' => 'Bounce', 'MessageID' => 'msg-1'],
        ]));

        $this->assertNull((new PostmarkProvider)->resolveWebhookEvent($request));
    }

    public function test_webhook_signature_rejects_when_credentials_are_missing(): void
    {
        $integration = Integration::create(['provider' => 'postmark', 'name' => 'Postmark']);

        $request = $this->webhookRequest('Basic '.base64_encode('user:pass'));

        $this->assertFalse((new PostmarkProvider)->verifyWebhookSignature($integration, $request));
    }

    public function test_webhook_signature_rejects_when_webhook_credentials_are_unset(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: ['server_token' => 'srv-abc'],
        );

        $request = $this->webhookRequest('Basic '.base64_encode('user:pass'));

        $this->assertFalse((new PostmarkProvider)->verifyWebhookSignature($integration, $request));
    }

    public function test_webhook_signature_rejects_when_authorization_header_is_missing(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: [
                'server_token' => 'srv-abc',
                'webhook_username' => 'user',
                'webhook_password' => 'pass',
            ],
        );

        $request = $this->webhookRequest(null);

        $this->assertFalse((new PostmarkProvider)->verifyWebhookSignature($integration, $request));
    }

    public function test_webhook_signature_rejects_non_basic_authorization(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: [
                'server_token' => 'srv-abc',
                'webhook_username' => 'user',
                'webhook_password' => 'pass',
            ],
        );

        $request = $this->webhookRequest('Bearer abc.def.ghi');

        $this->assertFalse((new PostmarkProvider)->verifyWebhookSignature($integration, $request));
    }

    public function test_webhook_signature_accepts_matching_basic_credentials(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: [
                'server_token' => 'srv-abc',
                'webhook_username' => 'hook-user',
                'webhook_password' => 'hook-pass',
            ],
        );

        $request = $this->webhookRequest('Basic '.base64_encode('hook-user:hook-pass'));

        $this->assertTrue((new PostmarkProvider)->verifyWebhookSignature($integration, $request));
    }

    public function test_webhook_signature_accepts_lowercase_basic_scheme(): void
    {
        // RFC 7235 §2.1 auth-schemes are case-insensitive. A client
        // (or a well-meaning middleware) might normalise the scheme to
        // lowercase, and we must still accept it.
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: [
                'server_token' => 'srv-abc',
                'webhook_username' => 'hook-user',
                'webhook_password' => 'hook-pass',
            ],
        );

        $request = $this->webhookRequest('basic '.base64_encode('hook-user:hook-pass'));

        $this->assertTrue((new PostmarkProvider)->verifyWebhookSignature($integration, $request));
    }

    public function test_webhook_signature_rejects_wrong_password(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: [
                'server_token' => 'srv-abc',
                'webhook_username' => 'hook-user',
                'webhook_password' => 'hook-pass',
            ],
        );

        $request = $this->webhookRequest('Basic '.base64_encode('hook-user:WRONG'));

        $this->assertFalse((new PostmarkProvider)->verifyWebhookSignature($integration, $request));
    }

    public function test_webhook_signature_rejects_garbage_base64(): void
    {
        $integration = $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: [
                'server_token' => 'srv-abc',
                'webhook_username' => 'hook-user',
                'webhook_password' => 'hook-pass',
            ],
        );

        $request = $this->webhookRequest('Basic !!!not-base64!!!');

        $this->assertFalse((new PostmarkProvider)->verifyWebhookSignature($integration, $request));
    }

    /**
     * Symfony's ServerBag parses the Authorization header into PHP_AUTH_USER
     * /PW only at Request construction time, so headers added afterwards
     * via `$request->headers->set()` don't reach `$request->getUser()`.
     * Pass it via the `$server` parameter to mirror what Apache/PHP-FPM
     * actually populates in production.
     */
    private function webhookRequest(?string $authorization): Request
    {
        $server = $authorization !== null ? ['HTTP_AUTHORIZATION' => $authorization] : [];

        return Request::create('/webhook', 'POST', server: $server, content: '{}');
    }

    public function test_handle_webhook_dispatches_generic_and_typed_events_for_bounce(): void
    {
        Event::fake();
        $integration = $this->makeIntegration();

        $payload = [
            'RecordType' => 'Bounce',
            'ID' => 12345,
            'Type' => 'HardBounce',
            'TypeCode' => 1,
            'MessageID' => 'msg-bounce-1',
            'MessageStream' => 'outbound',
            'Email' => 'gone@example.com',
            'From' => 'app@example.com',
            'BouncedAt' => '2026-04-23T10:00:00Z',
            'Inactive' => true,
            'CanActivate' => false,
            'Subject' => 'Hi',
            'Tag' => 'welcome',
            'Description' => 'mailbox does not exist',
            'Details' => 'smtp;550 5.1.1 user unknown',
        ];

        $request = Request::create('/webhook', 'POST', content: (string) json_encode($payload));

        $result = (new PostmarkProvider)->handleWebhook($integration, $request);

        $this->assertSame(['status' => 'dispatched', 'event' => 'Bounce'], $result);

        Event::assertDispatched(PostmarkWebhookReceived::class, function (PostmarkWebhookReceived $e) use ($integration, $payload): bool {
            return $e->integration->is($integration)
                && $e->recordType === 'Bounce'
                && $e->messageId === 'msg-bounce-1'
                && $e->payload === $payload;
        });

        Event::assertDispatched(PostmarkBounceReceived::class, function (PostmarkBounceReceived $e) use ($integration): bool {
            return $e->integration->is($integration)
                && $e->bounce->ID === 12345
                && $e->bounce->Type === PostmarkBounceType::HardBounce
                && $e->bounce->Email === 'gone@example.com'
                && $e->bounce->Inactive === true
                && $e->bounce->CanActivate === false;
        });
    }

    public function test_handle_webhook_dispatches_typed_delivery_event(): void
    {
        Event::fake();
        $integration = $this->makeIntegration();

        $payload = [
            'RecordType' => 'Delivery',
            'MessageID' => 'msg-delivery-1',
            'Recipient' => 'ok@example.com',
            'DeliveredAt' => '2026-04-23T10:00:00Z',
            'MessageStream' => 'outbound',
            'Tag' => 'welcome',
            'Details' => 'smtp;250 OK',
        ];

        $request = Request::create('/webhook', 'POST', content: (string) json_encode($payload));

        (new PostmarkProvider)->handleWebhook($integration, $request);

        Event::assertDispatched(PostmarkDeliveryReceived::class, function (PostmarkDeliveryReceived $e): bool {
            return $e->delivery->MessageID === 'msg-delivery-1'
                && $e->delivery->Recipient === 'ok@example.com';
        });
    }

    public function test_handle_webhook_dispatches_typed_open_event(): void
    {
        Event::fake();
        $integration = $this->makeIntegration();

        $payload = [
            'RecordType' => 'Open',
            'MessageID' => 'msg-open-1',
            'Recipient' => 'reader@example.com',
            'ReceivedAt' => '2026-04-23T10:00:00Z',
            'FirstOpen' => true,
            'MessageStream' => 'outbound',
            'ReadSeconds' => 17,
            'Tag' => 'welcome',
            'UserAgent' => 'Mozilla/5.0',
            'Platform' => 'Desktop',
        ];

        $request = Request::create('/webhook', 'POST', content: (string) json_encode($payload));

        (new PostmarkProvider)->handleWebhook($integration, $request);

        Event::assertDispatched(PostmarkOpenReceived::class, function (PostmarkOpenReceived $e): bool {
            return $e->open->MessageID === 'msg-open-1'
                && $e->open->FirstOpen === true
                && $e->open->ReadSeconds === 17;
        });
    }

    public function test_handle_webhook_dispatches_typed_click_event(): void
    {
        Event::fake();
        $integration = $this->makeIntegration();

        $payload = [
            'RecordType' => 'Click',
            'MessageID' => 'msg-click-1',
            'Recipient' => 'reader@example.com',
            'ReceivedAt' => '2026-04-23T10:00:00Z',
            'MessageStream' => 'outbound',
            'OriginalLink' => 'https://example.com/landing',
            'ClickLocation' => 'HTML',
            'Tag' => 'welcome',
        ];

        $request = Request::create('/webhook', 'POST', content: (string) json_encode($payload));

        (new PostmarkProvider)->handleWebhook($integration, $request);

        Event::assertDispatched(PostmarkClickReceived::class, function (PostmarkClickReceived $e): bool {
            return $e->click->OriginalLink === 'https://example.com/landing'
                && $e->click->ClickLocation === 'HTML';
        });
    }

    public function test_handle_webhook_dispatches_typed_spam_complaint_event(): void
    {
        Event::fake();
        $integration = $this->makeIntegration();

        $payload = [
            'RecordType' => 'SpamComplaint',
            'ID' => 999,
            'MessageID' => 'msg-spam-1',
            'MessageStream' => 'outbound',
            'Email' => 'angry@example.com',
            'BouncedAt' => '2026-04-23T10:00:00Z',
            'Inactive' => true,
            'CanActivate' => false,
        ];

        $request = Request::create('/webhook', 'POST', content: (string) json_encode($payload));

        (new PostmarkProvider)->handleWebhook($integration, $request);

        Event::assertDispatched(PostmarkSpamComplaintReceived::class, function (PostmarkSpamComplaintReceived $e): bool {
            return $e->complaint->ID === 999
                && $e->complaint->Email === 'angry@example.com';
        });
    }

    public function test_handle_webhook_dispatches_typed_subscription_change_event(): void
    {
        Event::fake();
        $integration = $this->makeIntegration();

        $payload = [
            'RecordType' => 'SubscriptionChange',
            'MessageID' => 'msg-sub-1',
            'MessageStream' => 'outbound',
            'Recipient' => 'unsub@example.com',
            'ChangedAt' => '2026-04-23T10:00:00Z',
            'Origin' => 'Recipient',
            'SuppressSending' => true,
            'SuppressionReason' => 'HardBounce',
        ];

        $request = Request::create('/webhook', 'POST', content: (string) json_encode($payload));

        (new PostmarkProvider)->handleWebhook($integration, $request);

        Event::assertDispatched(PostmarkSubscriptionChangeReceived::class, function (PostmarkSubscriptionChangeReceived $e): bool {
            return $e->change->Recipient === 'unsub@example.com'
                && $e->change->SuppressSending === true
                && $e->change->SuppressionReason === 'HardBounce';
        });
    }

    public function test_handle_webhook_dispatches_typed_inbound_event(): void
    {
        Event::fake();
        $integration = $this->makeIntegration();

        $payload = [
            'RecordType' => 'Inbound',
            'MessageID' => 'inb-1',
            'From' => 'sender@example.com',
            'To' => 'inbound+thread123@inbound.example.com',
            'Subject' => 'Re: hello',
            'Date' => 'Thu, 23 Apr 2026 10:00:00 +0000',
            'MailboxHash' => 'thread123',
            'TextBody' => 'Hi there',
        ];

        $request = Request::create('/webhook', 'POST', content: (string) json_encode($payload));

        (new PostmarkProvider)->handleWebhook($integration, $request);

        Event::assertDispatched(PostmarkInboundReceived::class, function (PostmarkInboundReceived $e): bool {
            return $e->message->MessageID === 'inb-1'
                && $e->message->MailboxHash === 'thread123';
        });
    }

    public function test_handle_webhook_dispatches_only_generic_event_for_unknown_record_type(): void
    {
        Event::fake();
        $integration = $this->makeIntegration();

        $payload = [
            'RecordType' => 'SomeFutureType',
            'MessageID' => 'msg-x',
        ];

        $request = Request::create('/webhook', 'POST', content: (string) json_encode($payload));

        $result = (new PostmarkProvider)->handleWebhook($integration, $request);

        $this->assertSame(['status' => 'dispatched', 'event' => 'SomeFutureType'], $result);

        Event::assertDispatched(PostmarkWebhookReceived::class);
        Event::assertNotDispatched(PostmarkBounceReceived::class);
        Event::assertNotDispatched(PostmarkDeliveryReceived::class);
        Event::assertNotDispatched(PostmarkOpenReceived::class);
        Event::assertNotDispatched(PostmarkClickReceived::class);
        Event::assertNotDispatched(PostmarkSpamComplaintReceived::class);
        Event::assertNotDispatched(PostmarkSubscriptionChangeReceived::class);
        Event::assertNotDispatched(PostmarkInboundReceived::class);
    }

    public function test_handle_webhook_tolerates_missing_record_type(): void
    {
        Event::fake();
        $integration = $this->makeIntegration();

        $request = Request::create('/webhook', 'POST', content: '{}');

        $result = (new PostmarkProvider)->handleWebhook($integration, $request);

        $this->assertSame(['status' => 'dispatched', 'event' => 'unknown'], $result);

        Event::assertDispatched(PostmarkWebhookReceived::class, function (PostmarkWebhookReceived $e): bool {
            return $e->recordType === 'unknown' && $e->messageId === '';
        });
    }

    public function test_health_check_returns_true_on_2xx_from_server_endpoint(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.postmarkapp.com/server' => Http::response(['ID' => 1, 'Name' => 'Test'], 200),
        ]);

        $integration = $this->makeIntegration();

        $this->assertTrue((new PostmarkProvider)->healthCheck($integration));
    }

    public function test_health_check_returns_false_on_401(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.postmarkapp.com/server' => Http::response(['Message' => 'Unauthorized'], 401),
        ]);

        $integration = $this->makeIntegration();

        $this->assertFalse((new PostmarkProvider)->healthCheck($integration));
    }

    public function test_health_check_returns_false_without_credentials(): void
    {
        $integration = Integration::create(['provider' => 'postmark', 'name' => 'Postmark']);

        $this->assertFalse((new PostmarkProvider)->healthCheck($integration));
    }

    /**
     * Quiet PHPStan: assert the typed payload classes are referenced so the
     * "unused use" rule doesn't fire. Each is exercised indirectly above
     * via Event listeners but PHPStan can't see through the event closures.
     */
    public function test_typed_payload_class_imports_resolve(): void
    {
        $this->assertTrue(class_exists(PostmarkBounceWebhookData::class));
        $this->assertTrue(class_exists(PostmarkDeliveryWebhookData::class));
        $this->assertTrue(class_exists(PostmarkOpenWebhookData::class));
        $this->assertTrue(class_exists(PostmarkClickWebhookData::class));
        $this->assertTrue(class_exists(PostmarkSpamComplaintWebhookData::class));
        $this->assertTrue(class_exists(PostmarkSubscriptionChangeWebhookData::class));
        $this->assertTrue(class_exists(PostmarkInboundWebhookData::class));
    }

    private function makeIntegration(): Integration
    {
        return $this->createIntegration(
            providerKey: 'postmark',
            providerClass: PostmarkProvider::class,
            credentials: [
                'server_token' => 'srv-abc',
                'webhook_username' => 'hook-user',
                'webhook_password' => 'hook-pass',
            ],
            metadata: ['message_stream' => 'outbound'],
        );
    }
}
