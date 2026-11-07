<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe;

use Integrations\Adapters\Stripe\Resources\StripeAccount;
use Integrations\Adapters\Stripe\Resources\StripeCharges;
use Integrations\Adapters\Stripe\Resources\StripeCustomers;
use Integrations\Adapters\Stripe\Resources\StripeDisputes;
use Integrations\Adapters\Stripe\Resources\StripeEvents;
use Integrations\Adapters\Stripe\Resources\StripePaymentIntents;
use Integrations\Adapters\Stripe\Resources\StripePaymentMethods;
use Integrations\Adapters\Stripe\Resources\StripeRefunds;
use Integrations\Adapters\Stripe\Resources\StripeSetupIntents;
use Integrations\Adapters\Stripe\Resources\StripeWebhookEndpoints;
use Integrations\Models\Integration;
use RuntimeException;
use Stripe\StripeClient as StripeSdkClient;

/**
 * Thin Stripe SDK wrapper. Exposes one accessor per resource we cover; the
 * resource classes go through Integration::request() so every call is logged,
 * rate-limited, and retried by the core package rather than by bespoke Stripe
 * code. Return types are Stripe's own typed objects (`\Stripe\Refund`,
 * `\Stripe\PaymentIntent`, etc.); the SDK already models them, so re-wrapping
 * them in local Data classes would duplicate work without adding value.
 */
class StripeClient
{
    private ?StripeSdkClient $sdk;

    private bool $booted = false;

    private ?StripePaymentIntents $paymentIntents = null;

    private ?StripeRefunds $refunds = null;

    private ?StripeCustomers $customers = null;

    private ?StripeCharges $charges = null;

    private ?StripeDisputes $disputes = null;

    private ?StripeEvents $events = null;

    private ?StripeWebhookEndpoints $webhookEndpoints = null;

    private ?StripeAccount $account = null;

    private ?StripeSetupIntents $setupIntents = null;

    private ?StripePaymentMethods $paymentMethods = null;

    public function __construct(
        private readonly Integration $integration,
        ?StripeSdkClient $sdk = null,
    ) {
        $this->sdk = $sdk;
    }

    public function paymentIntents(): StripePaymentIntents
    {
        return $this->paymentIntents ??= new StripePaymentIntents($this->integration, $this);
    }

    public function refunds(): StripeRefunds
    {
        return $this->refunds ??= new StripeRefunds($this->integration, $this);
    }

    public function customers(): StripeCustomers
    {
        return $this->customers ??= new StripeCustomers($this->integration, $this);
    }

    public function charges(): StripeCharges
    {
        return $this->charges ??= new StripeCharges($this->integration, $this);
    }

    public function disputes(): StripeDisputes
    {
        return $this->disputes ??= new StripeDisputes($this->integration, $this);
    }

    public function events(): StripeEvents
    {
        return $this->events ??= new StripeEvents($this->integration, $this);
    }

    public function webhookEndpoints(): StripeWebhookEndpoints
    {
        return $this->webhookEndpoints ??= new StripeWebhookEndpoints($this->integration, $this);
    }

    public function account(): StripeAccount
    {
        return $this->account ??= new StripeAccount($this->integration, $this);
    }

    public function setupIntents(): StripeSetupIntents
    {
        return $this->setupIntents ??= new StripeSetupIntents($this->integration, $this);
    }

    public function paymentMethods(): StripePaymentMethods
    {
        return $this->paymentMethods ??= new StripePaymentMethods($this->integration, $this);
    }

    public function getSdkClient(): StripeSdkClient
    {
        $this->boot();

        if ($this->sdk === null) {
            throw new RuntimeException('Stripe SDK client not initialized.');
        }

        return $this->sdk;
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $credentials = $this->integration->credentials;

        if (! $credentials instanceof StripeCredentials) {
            throw new RuntimeException(sprintf(
                'Stripe integration (id=%s): credentials must be %s, got %s.',
                $this->integration->id ?? 'null',
                StripeCredentials::class,
                get_debug_type($credentials),
            ));
        }

        if ($this->sdk === null) {
            $this->sdk = new StripeSdkClient($credentials->api_key);
        }

        $this->booted = true;
    }
}
