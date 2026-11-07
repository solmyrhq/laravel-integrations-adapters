<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\Enums\StripeSetupFutureUsage;
use Integrations\Adapters\Stripe\StripeResource;
use Integrations\Exceptions\IdempotencyConflict;
use Integrations\RequestContext;
use Stripe\SetupIntent;

/**
 * Wrapper around Stripe's SetupIntent service. This class has no `confirm()`:
 * confirm the intent in the browser with Stripe.js and its `client_secret`.
 */
class StripeSetupIntents extends StripeResource
{
    /**
     * Pass `$idempotencyKey` to make the call at most once. The package
     * records the key before it calls the SDK, throws
     * {@see IdempotencyConflict} on a second call with the same key, and
     * sends the key to Stripe as the `Idempotency-Key` header.
     *
     * @param  array<string, string>|null  $metadata
     * @param  list<string>|null  $allowedPaymentMethodTypes
     */
    public function create(
        string $customer,
        StripeSetupFutureUsage $usage = StripeSetupFutureUsage::OffSession,
        bool $automaticPaymentMethods = true,
        ?array $allowedPaymentMethodTypes = null,
        ?string $description = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
    ): SetupIntent {
        $this->assertId($customer);

        $params = [
            'customer' => $customer,
            'usage' => $usage->value,
            ...$this->methodSelection($automaticPaymentMethods, $allowedPaymentMethodTypes),
        ];
        if ($description !== null) {
            $params['description'] = $description;
        }
        if ($metadata !== null) {
            $params['metadata'] = $metadata;
        }

        $response = $this->integration
            ->at('setup_intents')
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(fn (RequestContext $ctx): SetupIntent => $this->callStripe(
                $ctx,
                fn (): SetupIntent => $this->sdk()->setupIntents->create(
                    $params,
                    $this->stripeOptions($ctx),
                ),
            ));

        return $this->expectInstance($response, SetupIntent::class);
    }

    public function retrieve(string $id): SetupIntent
    {
        $this->assertId($id);

        $response = $this->integration
            ->at("setup_intents/{$id}")
            ->get(fn (RequestContext $ctx): SetupIntent => $this->callStripe(
                $ctx,
                fn (): SetupIntent => $this->sdk()->setupIntents->retrieve($id),
            ));

        return $this->expectInstance($response, SetupIntent::class);
    }

    public function cancel(string $id, ?string $cancellationReason = null, ?string $idempotencyKey = null): SetupIntent
    {
        $this->assertId($id);

        $params = [];
        if ($cancellationReason !== null) {
            $params['cancellation_reason'] = $cancellationReason;
        }

        $response = $this->integration
            ->at("setup_intents/{$id}/cancel")
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(fn (RequestContext $ctx): SetupIntent => $this->callStripe(
                $ctx,
                fn (): SetupIntent => $this->sdk()->setupIntents->cancel(
                    $id,
                    $params,
                    $this->stripeOptions($ctx),
                ),
            ));

        return $this->expectInstance($response, SetupIntent::class);
    }
}
