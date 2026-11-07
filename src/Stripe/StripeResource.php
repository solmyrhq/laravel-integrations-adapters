<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe;

use Integrations\Models\Integration;
use Integrations\RequestContext;
use InvalidArgumentException;
use Stripe\Exception\ApiErrorException;
use Stripe\StripeClient as StripeSdkClient;
use Stripe\StripeObject;
use Stripe\Util\CaseInsensitiveArray;
use UnexpectedValueException;

abstract class StripeResource
{
    public function __construct(
        protected readonly Integration $integration,
        protected readonly StripeClient $client,
    ) {}

    protected function sdk(): StripeSdkClient
    {
        return $this->client->getSdkClient();
    }

    /**
     * Empty ids route to the Stripe list endpoint (e.g. `refunds/` instead of
     * `refunds/re_123`), silently returning the wrong shape. Fail fast.
     */
    protected function assertId(string $id): void
    {
        if ($id === '') {
            throw new InvalidArgumentException('Stripe resource id cannot be empty.');
        }
    }

    /**
     * Returns an intent's `automatic_payment_methods` or
     * `allowed_payment_method_types` param, never both: Stripe rejects an
     * intent that sets both.
     *
     * @param  list<string>|null  $allowedPaymentMethodTypes
     * @return array{automatic_payment_methods: array{enabled: bool}}|array{allowed_payment_method_types: list<string>}
     */
    protected function methodSelection(bool $automaticPaymentMethods, ?array $allowedPaymentMethodTypes): array
    {
        if ($allowedPaymentMethodTypes === null) {
            return ['automatic_payment_methods' => ['enabled' => $automaticPaymentMethods]];
        }

        if ($automaticPaymentMethods) {
            throw new InvalidArgumentException('Pass automaticPaymentMethods: false with allowedPaymentMethodTypes.');
        }

        if ($allowedPaymentMethodTypes === []) {
            throw new InvalidArgumentException('Stripe allowedPaymentMethodTypes cannot be empty.');
        }

        return ['allowed_payment_method_types' => $allowedPaymentMethodTypes];
    }

    protected function assertPositive(int $value, string $parameter): void
    {
        if ($value <= 0) {
            throw new InvalidArgumentException(sprintf(
                'Stripe %s must be positive, got %d.',
                $parameter,
                $value,
            ));
        }
    }

    /**
     * Pull what we can from Stripe's response (request ID, rate-limit info
     * when present) and feed it back to core via the RequestContext.
     * Stripe stores the last response on the returned StripeObject (not on
     * the client), so reporting needs the actual resource. On the error
     * path, ApiErrorException carries the same metadata directly.
     *
     * Stripe sets `Request-Id` on every response, which is the value
     * support tickets ask for. Rate-limit headers are only emitted on 429s,
     * so most calls report just the request ID.
     *
     * Most callers should reach for {@see callStripe()}, which wraps the
     * SDK call in the success/error reporting boilerplate.
     */
    protected function reportStripeMetadata(
        RequestContext $ctx,
        StripeObject|ApiErrorException|null $source,
    ): void {
        if ($source === null) {
            return;
        }

        if ($source instanceof ApiErrorException) {
            $requestId = $source->getRequestId();
        } else {
            $last = $source->getLastResponse();
            if ($last === null) {
                return;
            }
            $requestId = $this->headerString($last->headers, 'Request-Id');
        }

        if ($requestId !== null && $requestId !== '') {
            $ctx->reportResponseMetadata(providerRequestId: $requestId);
        }
    }

    /**
     * Build the Stripe-side request options array, conditionally including
     * the idempotency key. Stripe's typed array shape rejects `null` for
     * `idempotency_key`, so we omit the key entirely when the context
     * doesn't carry one.
     *
     * @return array{idempotency_key?: string}
     */
    protected function stripeOptions(RequestContext $ctx): array
    {
        if ($ctx->idempotencyKey === null) {
            return [];
        }

        return ['idempotency_key' => $ctx->idempotencyKey];
    }

    /**
     * Run an SDK call and report its metadata to core regardless of
     * outcome. Returns whatever the call produced; rethrows
     * ApiErrorException after recording the request ID so the failed
     * request still has a paste-ready ID for support tickets.
     *
     * @template T of StripeObject
     *
     * @param  callable(): T  $sdkCall
     * @return T
     *
     * @param-immediately-invoked-callable $sdkCall
     */
    protected function callStripe(RequestContext $ctx, callable $sdkCall): StripeObject
    {
        try {
            $result = $sdkCall();
        } catch (ApiErrorException $e) {
            $this->reportStripeMetadata($ctx, $e);

            throw $e;
        }

        $this->reportStripeMetadata($ctx, $result);

        return $result;
    }

    /**
     * Read a header value out of Stripe's response. The SDK's own
     * `ApiResponse::$headers` PHPDoc widens to `array|CaseInsensitiveArray|null`,
     * so we accept that union and treat the value as opaque until the
     * `is_string` check below pins it down.
     */
    private function headerString(mixed $headers, string $name): ?string
    {
        if (! is_array($headers) && ! ($headers instanceof CaseInsensitiveArray)) {
            return null;
        }

        $value = $headers[$name] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Narrow the `mixed` return from `Integration::request()` back to the
     * Stripe type the closure produced.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    protected function expectInstance(mixed $value, string $class): object
    {
        if (! $value instanceof $class) {
            throw new UnexpectedValueException(sprintf(
                'Expected instance of %s, got %s.',
                $class,
                get_debug_type($value),
            ));
        }

        return $value;
    }
}
