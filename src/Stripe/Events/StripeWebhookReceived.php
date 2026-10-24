<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Integrations\Models\Integration;

/**
 * Emitted by StripeProvider::handleWebhook after signature verification.
 * Consumers listen for this event and route by `$eventType` to whatever
 * Action owns the downstream side-effects. The raw `$payload` array is
 * exactly what Stripe sent. For typed access, build the matching stripe-php
 * object from it, e.g. \Stripe\PaymentIntent::constructFrom($payload['data']['object']).
 *
 * A thin event (`object: v2.core.event`, with an `$eventType` such as
 * `v1.billing.meter.error_report_triggered`) has no `data.object`. When it
 * concerns an object, `$payload['related_object']` has that object's `id`,
 * `type` and `url`.
 */
final class StripeWebhookReceived
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly Integration $integration,
        public readonly string $eventType,
        public readonly string $eventId,
        public readonly array $payload,
    ) {}
}
