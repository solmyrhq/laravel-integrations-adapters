<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\Enums\StripeSetupFutureUsage;
use Integrations\Adapters\Stripe\StripeResource;
use Integrations\Exceptions\IdempotencyConflict;
use Integrations\RequestContext;
use InvalidArgumentException;
use Stripe\Collection;
use Stripe\PaymentIntent;

/**
 * Wrapper around Stripe's PaymentIntent service. Exposes typed named arguments
 * for the common fields. When you need a Stripe option we haven't exposed yet,
 * extend this class or open a PR against the adapter; the deliberate
 * alternative is a `$params` passthrough that breaks type inference and makes
 * the adapter a thin rename over `\Stripe\StripeClient`.
 */
class StripePaymentIntents extends StripeResource
{
    /**
     * Pass `$idempotencyKey` (e.g. `"charge:order-{$order->id}"`) to make
     * the call at-most-once: the package writes a row in
     * `integration_idempotency_keys` before the SDK call fires, throws
     * {@see IdempotencyConflict} on a second
     * call with the same key, and forwards the key as Stripe's
     * `Idempotency-Key` header so Stripe also dedupes upstream. Pass
     * `null` (the default) to skip idempotency entirely.
     *
     * To charge a saved method when the customer is not present, for example
     * for a renewal, pass `$customer`, `$paymentMethod`, `confirm: true` and
     * `offSession: true`. If the bank declines the charge, the SDK throws
     * Stripe's `CardException`. Its `getError()->payment_intent` holds the
     * PaymentIntent, and its `getDeclineCode()` returns a code such as
     * `authentication_required`.
     *
     * To limit the method types, pass `$allowedPaymentMethodTypes` with
     * `automaticPaymentMethods: false`. A list with
     * `automaticPaymentMethods: true` throws `InvalidArgumentException`.
     *
     * @param  array<string, string>|null  $metadata
     * @param  list<string>|null  $allowedPaymentMethodTypes
     */
    public function create(
        int $amount,
        string $currency,
        ?string $customer = null,
        ?string $receiptEmail = null,
        ?string $description = null,
        ?array $metadata = null,
        bool $automaticPaymentMethods = true,
        ?string $idempotencyKey = null,
        ?string $paymentMethod = null,
        ?array $allowedPaymentMethodTypes = null,
        ?StripeSetupFutureUsage $setupFutureUsage = null,
        bool $confirm = false,
        bool $offSession = false,
        ?string $returnUrl = null,
    ): PaymentIntent {
        $this->assertPositive($amount, 'amount');
        $this->assertConfirmation($confirm, $offSession, $returnUrl, $paymentMethod);

        $params = $this->createParams(
            $amount,
            $currency,
            $customer,
            $receiptEmail,
            $description,
            $metadata,
            $automaticPaymentMethods,
            $allowedPaymentMethodTypes,
        );
        if ($paymentMethod !== null) {
            $this->assertId($paymentMethod);
            $params['payment_method'] = $paymentMethod;
        }
        if ($setupFutureUsage !== null) {
            $params['setup_future_usage'] = $setupFutureUsage->value;
        }
        if ($confirm) {
            $params['confirm'] = true;
        }
        if ($offSession) {
            $params['off_session'] = true;
        }
        if ($returnUrl !== null) {
            $params['return_url'] = $returnUrl;
        }

        $response = $this->integration
            ->at('payment_intents')
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(fn (RequestContext $ctx): PaymentIntent => $this->callStripe(
                $ctx,
                fn (): PaymentIntent => $this->sdk()->paymentIntents->create(
                    $params,
                    $this->stripeOptions($ctx),
                ),
            ));

        return $this->expectInstance($response, PaymentIntent::class);
    }

    public function retrieve(string $id): PaymentIntent
    {
        $this->assertId($id);

        $response = $this->integration
            ->at("payment_intents/{$id}")
            ->get(fn (RequestContext $ctx): PaymentIntent => $this->callStripe(
                $ctx,
                fn (): PaymentIntent => $this->sdk()->paymentIntents->retrieve($id),
            ));

        return $this->expectInstance($response, PaymentIntent::class);
    }

    /**
     * @param  array<string, string>|null  $metadata
     */
    public function update(
        string $id,
        ?string $description = null,
        ?string $receiptEmail = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
    ): PaymentIntent {
        $this->assertId($id);

        $params = [];
        if ($description !== null) {
            $params['description'] = $description;
        }
        if ($receiptEmail !== null) {
            $params['receipt_email'] = $receiptEmail;
        }
        if ($metadata !== null) {
            $params['metadata'] = $metadata;
        }

        $response = $this->integration
            ->at("payment_intents/{$id}")
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(fn (RequestContext $ctx): PaymentIntent => $this->callStripe(
                $ctx,
                fn (): PaymentIntent => $this->sdk()->paymentIntents->update(
                    $id,
                    $params,
                    $this->stripeOptions($ctx),
                ),
            ));

        return $this->expectInstance($response, PaymentIntent::class);
    }

    /**
     * Pass `offSession: true` to retry a saved method when the customer is not
     * present. If the bank declines the charge, the SDK throws Stripe's
     * `CardException`, as in `create()`.
     */
    public function confirm(
        string $id,
        ?string $paymentMethod = null,
        ?string $idempotencyKey = null,
        bool $offSession = false,
        ?string $returnUrl = null,
        ?StripeSetupFutureUsage $setupFutureUsage = null,
    ): PaymentIntent {
        $this->assertId($id);

        $params = [];
        if ($paymentMethod !== null) {
            $this->assertId($paymentMethod);
            $params['payment_method'] = $paymentMethod;
        }
        if ($offSession) {
            $params['off_session'] = true;
        }
        if ($returnUrl !== null) {
            $params['return_url'] = $returnUrl;
        }
        if ($setupFutureUsage !== null) {
            $params['setup_future_usage'] = $setupFutureUsage->value;
        }

        $response = $this->integration
            ->at("payment_intents/{$id}/confirm")
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(fn (RequestContext $ctx): PaymentIntent => $this->callStripe(
                $ctx,
                fn (): PaymentIntent => $this->sdk()->paymentIntents->confirm(
                    $id,
                    $params,
                    $this->stripeOptions($ctx),
                ),
            ));

        return $this->expectInstance($response, PaymentIntent::class);
    }

    public function capture(string $id, ?int $amountToCapture = null, ?string $idempotencyKey = null): PaymentIntent
    {
        $this->assertId($id);

        $params = [];
        if ($amountToCapture !== null) {
            $this->assertPositive($amountToCapture, 'amountToCapture');
            $params['amount_to_capture'] = $amountToCapture;
        }

        $response = $this->integration
            ->at("payment_intents/{$id}/capture")
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(fn (RequestContext $ctx): PaymentIntent => $this->callStripe(
                $ctx,
                fn (): PaymentIntent => $this->sdk()->paymentIntents->capture(
                    $id,
                    $params,
                    $this->stripeOptions($ctx),
                ),
            ));

        return $this->expectInstance($response, PaymentIntent::class);
    }

    public function cancel(string $id, ?string $cancellationReason = null, ?string $idempotencyKey = null): PaymentIntent
    {
        $this->assertId($id);

        $params = [];
        if ($cancellationReason !== null) {
            $params['cancellation_reason'] = $cancellationReason;
        }

        $response = $this->integration
            ->at("payment_intents/{$id}/cancel")
            ->withData($params)
            ->withIdempotencyKey($idempotencyKey)
            ->post(fn (RequestContext $ctx): PaymentIntent => $this->callStripe(
                $ctx,
                fn (): PaymentIntent => $this->sdk()->paymentIntents->cancel(
                    $id,
                    $params,
                    $this->stripeOptions($ctx),
                ),
            ));

        return $this->expectInstance($response, PaymentIntent::class);
    }

    /**
     * @return Collection<PaymentIntent>
     */
    public function list(?string $customer = null, ?int $limit = null): Collection
    {
        $params = [];
        if ($customer !== null) {
            $this->assertId($customer);
            $params['customer'] = $customer;
        }
        if ($limit !== null) {
            $this->assertPositive($limit, 'limit');
            $params['limit'] = $limit;
        }

        $response = $this->integration
            ->at('payment_intents')
            ->withData($params)
            ->get(fn (RequestContext $ctx): Collection => $this->callStripe(
                $ctx,
                fn (): Collection => $this->sdk()->paymentIntents->all($params),
            ));

        return $this->expectInstance($response, Collection::class);
    }

    private function assertConfirmation(bool $confirm, bool $offSession, ?string $returnUrl, ?string $paymentMethod): void
    {
        if (! $confirm && ($offSession || $returnUrl !== null)) {
            throw new InvalidArgumentException('Pass confirm: true to send Stripe off_session or return_url.');
        }

        if ($confirm && $paymentMethod === null) {
            throw new InvalidArgumentException('Pass a paymentMethod with Stripe PaymentIntent confirm: true.');
        }
    }

    /**
     * @param  array<string, string>|null  $metadata
     * @param  list<string>|null  $allowedPaymentMethodTypes
     * @return array{amount: int, currency: string, automatic_payment_methods?: array{enabled: bool}, allowed_payment_method_types?: list<string>, customer?: string, receipt_email?: string, description?: string, metadata?: array<string, string>}
     */
    private function createParams(
        int $amount,
        string $currency,
        ?string $customer,
        ?string $receiptEmail,
        ?string $description,
        ?array $metadata,
        bool $automaticPaymentMethods,
        ?array $allowedPaymentMethodTypes,
    ): array {
        $params = ['amount' => $amount, 'currency' => $currency, ...$this->methodSelection($automaticPaymentMethods, $allowedPaymentMethodTypes)];

        if ($customer !== null) {
            $this->assertId($customer);
            $params['customer'] = $customer;
        }
        if ($receiptEmail !== null) {
            $params['receipt_email'] = $receiptEmail;
        }
        if ($description !== null) {
            $params['description'] = $description;
        }
        if ($metadata !== null) {
            $params['metadata'] = $metadata;
        }

        return $params;
    }
}
