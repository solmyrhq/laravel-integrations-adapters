<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Resources;

use Integrations\Adapters\Stripe\StripeResource;
use Integrations\RequestContext;
use Stripe\Collection;
use Stripe\PaymentMethod;

/**
 * Wrapper around Stripe's PaymentMethod service, for the methods saved to a
 * customer. This class has no `create()`: Stripe creates a method and attaches
 * it to the customer when a SetupIntent, or a PaymentIntent with
 * `setupFutureUsage`, is confirmed in the browser.
 */
class StripePaymentMethods extends StripeResource
{
    public function retrieve(string $id): PaymentMethod
    {
        $this->assertId($id);

        $response = $this->integration
            ->at("payment_methods/{$id}")
            ->get(fn (RequestContext $ctx): PaymentMethod => $this->callStripe(
                $ctx,
                fn (): PaymentMethod => $this->sdk()->paymentMethods->retrieve($id),
            ));

        return $this->expectInstance($response, PaymentMethod::class);
    }

    /**
     * Detach the method from its customer. After that, the method cannot be
     * used for a payment or attached to a customer again.
     */
    public function detach(string $id, ?string $idempotencyKey = null): PaymentMethod
    {
        $this->assertId($id);

        $response = $this->integration
            ->at("payment_methods/{$id}/detach")
            ->withIdempotencyKey($idempotencyKey)
            ->post(fn (RequestContext $ctx): PaymentMethod => $this->callStripe(
                $ctx,
                fn (): PaymentMethod => $this->sdk()->paymentMethods->detach(
                    $id,
                    null,
                    $this->stripeOptions($ctx),
                ),
            ));

        return $this->expectInstance($response, PaymentMethod::class);
    }

    /**
     * @return Collection<PaymentMethod>
     */
    public function listForCustomer(string $customer, ?string $type = null, ?int $limit = null): Collection
    {
        $this->assertId($customer);

        $params = [];
        if ($type !== null) {
            $params['type'] = $type;
        }
        if ($limit !== null) {
            $this->assertPositive($limit, 'limit');
            $params['limit'] = $limit;
        }

        $response = $this->integration
            ->at("customers/{$customer}/payment_methods")
            ->withData($params)
            ->get(fn (RequestContext $ctx): Collection => $this->callStripe(
                $ctx,
                fn (): Collection => $this->sdk()->customers->allPaymentMethods($customer, $params),
            ));

        return $this->expectInstance($response, Collection::class);
    }
}
