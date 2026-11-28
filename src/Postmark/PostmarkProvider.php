<?php

declare(strict_types=1);

namespace Integrations\Adapters\Postmark;

use GuzzleHttp\Exception\ConnectException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Integrations\Adapters\Postmark\Data\PostmarkBounceWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkClickWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkDeliveryWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkInboundWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkOpenWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkSpamComplaintWebhookData;
use Integrations\Adapters\Postmark\Data\PostmarkSubscriptionChangeWebhookData;
use Integrations\Adapters\Postmark\Enums\PostmarkWebhookRecordType;
use Integrations\Adapters\Postmark\Events\PostmarkBounceReceived;
use Integrations\Adapters\Postmark\Events\PostmarkClickReceived;
use Integrations\Adapters\Postmark\Events\PostmarkDeliveryReceived;
use Integrations\Adapters\Postmark\Events\PostmarkInboundReceived;
use Integrations\Adapters\Postmark\Events\PostmarkOpenReceived;
use Integrations\Adapters\Postmark\Events\PostmarkSpamComplaintReceived;
use Integrations\Adapters\Postmark\Events\PostmarkSubscriptionChangeReceived;
use Integrations\Adapters\Postmark\Events\PostmarkWebhookReceived;
use Integrations\Contracts\ClassifiesFailures;
use Integrations\Contracts\HandlesWebhooks;
use Integrations\Contracts\HasHealthCheck;
use Integrations\Contracts\IdentifiesAuthenticatedUser;
use Integrations\Contracts\IntegrationProvider;
use Integrations\Data\AuthenticatedUser;
use Integrations\Enums\FailureClass;
use Integrations\Models\Integration;
use RuntimeException;
use Throwable;

/**
 * Postmark integration provider. Three jobs:
 *
 * 1. Standard adapter surface: credential typing, validation rules, and a
 *    health check that hits the `/server` endpoint.
 * 2. Webhook ingestion. Postmark doesn't sign with HMAC, so signature
 *    verification compares the inbound `Authorization: Basic` header
 *    against stored credentials. After verification, `handleWebhook()`
 *    fires the generic `PostmarkWebhookReceived` event followed by the
 *    typed event matching the payload's `RecordType`.
 * 3. Mailer credentials bridge. `registerMailerOverride()` is called from
 *    the package service provider's `boot()` and lazy-wires the host
 *    app's Postmark mailer config from a single registered Postmark
 *    integration record. Multi-tenant apps with several Postmark
 *    integrations should call `useForMail()` per request to swap which
 *    one is active.
 */
class PostmarkProvider implements ClassifiesFailures, HandlesWebhooks, HasHealthCheck, IdentifiesAuthenticatedUser, IntegrationProvider
{
    #[\Override]
    public function classifyFailure(Throwable $e): ?FailureClass
    {
        // A connection-level error carries no HTTP status, so core can't see
        // it; map it to an upstream fault. The Postmark SDK exposes the status
        // via getHttpStatusCode(), which core's duck-typing already reads, so
        // 4xx/5xx/429 are deferred to the default classifier.
        if ($e instanceof ConnectException) {
            return FailureClass::Upstream;
        }

        return null;
    }

    #[\Override]
    public function name(): string
    {
        return 'Postmark';
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function credentialRules(): array
    {
        return [
            'server_token' => ['required', 'string'],
            // Webhook auth must be configured as a pair: verifyWebhookSignature
            // needs both halves, so accepting one without the other would
            // silently fail every inbound delivery.
            'webhook_username' => ['nullable', 'string', 'required_with:webhook_password'],
            'webhook_password' => ['nullable', 'string', 'required_with:webhook_username'],
            'account_token' => ['nullable', 'string'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    #[\Override]
    public function metadataRules(): array
    {
        return [
            // `message_stream` is omitted-or-string. PostmarkMetadata's
            // constructor supplies the 'outbound' default when the field is
            // absent; allowing explicit null here would just bounce off the
            // non-nullable `string` property during hydration.
            'message_stream' => ['sometimes', 'string'],
            'server_name' => ['nullable', 'string'],
        ];
    }

    /**
     * @return class-string<PostmarkCredentials>
     */
    #[\Override]
    public function credentialDataClass(): string
    {
        return PostmarkCredentials::class;
    }

    /**
     * @return class-string<PostmarkMetadata>
     */
    #[\Override]
    public function metadataDataClass(): string
    {
        return PostmarkMetadata::class;
    }

    #[\Override]
    public function healthCheck(Integration $integration): bool
    {
        $credentials = $integration->credentials;
        if (! $credentials instanceof PostmarkCredentials) {
            return false;
        }

        try {
            $response = Http::withHeaders([
                'X-Postmark-Server-Token' => $credentials->server_token,
                'Accept' => 'application/json',
            ])
                ->connectTimeout(5)
                ->timeout(10)
                ->get('https://api.postmarkapp.com/server');

            return $response->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * The Postmark server the token authenticates as. A server token is scoped
     * to a single server, so the server is the principal: its id and name are
     * mapped onto the user shape, with no username or email to surface.
     */
    #[\Override]
    public function authenticatedUser(Integration $integration): AuthenticatedUser
    {
        $server = $this->makeClient($integration)->server()->retrieve();

        $id = $server['ID'] ?? null;
        if (! is_int($id) && ! is_string($id)) {
            throw new RuntimeException('Postmark GET /server returned no usable server id; got '.get_debug_type($id).'.');
        }

        $name = $server['Name'] ?? null;

        return new AuthenticatedUser(
            id: (string) $id,
            username: null,
            name: is_string($name) ? $name : null,
            email: null,
            raw: $server,
        );
    }

    protected function makeClient(Integration $integration): PostmarkClient
    {
        return new PostmarkClient($integration);
    }

    /**
     * Verify the webhook by comparing the inbound `Authorization: Basic` header
     * against stored credentials. Postmark secures webhooks via HTTP Basic
     * Auth on the URL or IP allowlisting; it does not sign individual
     * requests. Returns false if either credential side is missing so an
     * unconfigured integration fails closed.
     *
     * Symfony's HTTP foundation already parses the Authorization header
     * (case-insensitive scheme, base64 decode, split on `:`) and exposes
     * the result via `$request->getUser()` / `$request->getPassword()`, so
     * we just compare those against the stored credentials in constant time.
     */
    #[\Override]
    public function verifyWebhookSignature(Integration $integration, Request $request): bool
    {
        $credentials = $integration->credentials;
        if (! $credentials instanceof PostmarkCredentials) {
            return false;
        }

        if ($credentials->webhook_username === null || $credentials->webhook_password === null) {
            return false;
        }

        $user = $request->getUser();
        $pass = $request->getPassword();

        if ($user === null || $pass === null) {
            return false;
        }

        return hash_equals(
            $credentials->webhook_username.':'.$credentials->webhook_password,
            $user.':'.$pass,
        );
    }

    #[\Override]
    public function resolveWebhookEvent(Request $request): ?string
    {
        $payload = $this->decodePayload($request);

        $type = $payload['RecordType'] ?? null;

        return is_string($type) ? $type : null;
    }

    /**
     * @return array<string, class-string|callable(Integration, Request): mixed|array{class-string, string}>
     */
    #[\Override]
    public function webhookHandlers(): array
    {
        return [];
    }

    /**
     * Returning null lets the core fall back to a payload-hash dedup key.
     * Postmark doesn't ship a stable per-delivery header, and using just the
     * payload's `MessageID` would collapse legitimate distinct events for
     * the same message (multiple opens, multiple clicks).
     */
    #[\Override]
    public function webhookDeliveryId(Request $request): ?string
    {
        return null;
    }

    /**
     * @return array{status: string, event: string}
     */
    #[\Override]
    public function handleWebhook(Integration $integration, Request $request): array
    {
        $payload = $this->decodePayload($request);

        $rawType = is_string($payload['RecordType'] ?? null) ? $payload['RecordType'] : 'unknown';
        $messageId = is_string($payload['MessageID'] ?? null) ? $payload['MessageID'] : '';

        PostmarkWebhookReceived::dispatch($integration, $rawType, $messageId, $payload);

        $type = PostmarkWebhookRecordType::tryFrom($rawType);
        if ($type === null) {
            if ($rawType !== 'unknown') {
                Log::warning('PostmarkProvider: unrecognised RecordType, no typed event dispatched.', [
                    'integration_id' => $integration->id,
                    'record_type' => $rawType,
                ]);
            }

            return ['status' => 'dispatched', 'event' => $rawType];
        }

        $this->dispatchTypedEvent($integration, $type, $payload);

        return ['status' => 'dispatched', 'event' => $type->value];
    }

    /**
     * Lazy mailer-config bridge. Hooks the container's `mail.manager`
     * resolution so the database lookup only happens when the host app
     * actually uses mail. We don't want to query Integrations on every
     * request just to handle the case where mail might be sent.
     *
     * Skips silently for zero or multiple Postmark integrations: zero
     * leaves the host app's existing config alone; multiple means a
     * multi-tenant setup where the host must pick per-request via
     * `useForMail()`.
     */
    public static function registerMailerOverride(): void
    {
        $applied = false;

        app()->resolving('mail.manager', function () use (&$applied): void {
            if ($applied) {
                return;
            }
            $applied = true;

            self::autoApplySingleIntegration();
        });
    }

    private static function autoApplySingleIntegration(): void
    {
        try {
            $integrations = Integration::query()
                ->forProvider('postmark')
                ->active()
                ->get();
        } catch (Throwable) {
            return;
        }

        if ($integrations->count() !== 1) {
            return;
        }

        $integration = $integrations->first();
        if ($integration === null) {
            return;
        }

        self::applyMailConfig($integration);
    }

    /**
     * Runtime escape hatch for multi-integration setups: call this before
     * `Mail::send()` to point Laravel's Postmark mailer at this specific
     * integration's credentials for the rest of the current request.
     */
    public function useForMail(Integration $integration): void
    {
        self::applyMailConfig($integration);
    }

    private static function applyMailConfig(Integration $integration): void
    {
        $credentials = $integration->credentials;
        $metadata = $integration->metadata;

        if (! $credentials instanceof PostmarkCredentials) {
            throw new RuntimeException(sprintf(
                'Postmark integration (id=%s): credentials must be %s, got %s.',
                $integration->id ?? 'null',
                PostmarkCredentials::class,
                get_debug_type($credentials),
            ));
        }

        $stream = $metadata instanceof PostmarkMetadata ? $metadata->message_stream : 'outbound';

        config([
            'services.postmark.token' => $credentials->server_token,
            'services.postmark.message_stream_id' => $stream,
            'mail.mailers.postmark.transport' => 'postmark',
            'mail.mailers.postmark.message_stream_id' => $stream,
        ]);

        // Three caches sit between us and the next Mail::send():
        //   1. MailManager's own per-mailer cache (Mail::forgetMailers)
        //   2. The Mail facade's resolved-instance cache
        //      (Mail::clearResolvedInstance)
        //   3. The container's singleton (app()->forgetInstance)
        // Forgetting only the container leaves the facade holding the stale
        // manager, so anything that already touched Mail::* in this request
        // would keep using the old token.
        if (app()->resolved('mail.manager')) {
            Mail::forgetMailers();
            Mail::clearResolvedInstance('mail.manager');
            app()->forgetInstance('mail.manager');
        }
    }

    /**
     * Build the typed event for the given record type and dispatch it. Using
     * `match` (with no default arm) means PHPStan flags any new
     * PostmarkWebhookRecordType case that isn't handled here, and PHP itself
     * throws UnhandledMatchError at runtime if a new case ever slipped past
     * static analysis. The previous if/elseif chain silently fell through
     * to PostmarkInboundReceived, which would have been wrong for any
     * future record type Postmark adds.
     *
     * @param  array<string, mixed>  $payload
     */
    private function dispatchTypedEvent(Integration $integration, PostmarkWebhookRecordType $type, array $payload): void
    {
        $event = match ($type) {
            PostmarkWebhookRecordType::Delivery => new PostmarkDeliveryReceived($integration, PostmarkDeliveryWebhookData::from($payload)),
            PostmarkWebhookRecordType::Bounce => new PostmarkBounceReceived($integration, PostmarkBounceWebhookData::from($payload)),
            PostmarkWebhookRecordType::Open => new PostmarkOpenReceived($integration, PostmarkOpenWebhookData::from($payload)),
            PostmarkWebhookRecordType::Click => new PostmarkClickReceived($integration, PostmarkClickWebhookData::from($payload)),
            PostmarkWebhookRecordType::SpamComplaint => new PostmarkSpamComplaintReceived($integration, PostmarkSpamComplaintWebhookData::from($payload)),
            PostmarkWebhookRecordType::SubscriptionChange => new PostmarkSubscriptionChangeReceived($integration, PostmarkSubscriptionChangeWebhookData::from($payload)),
            PostmarkWebhookRecordType::Inbound => new PostmarkInboundReceived($integration, PostmarkInboundWebhookData::from($payload)),
        };

        event($event);
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePayload(Request $request): array
    {
        return array_filter($request->json()->all(), is_string(...), ARRAY_FILTER_USE_KEY);
    }
}
