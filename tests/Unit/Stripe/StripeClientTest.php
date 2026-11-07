<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Stripe;

use Integrations\Adapters\Stripe\Resources\StripeCharges;
use Integrations\Adapters\Stripe\Resources\StripeCustomers;
use Integrations\Adapters\Stripe\Resources\StripeDisputes;
use Integrations\Adapters\Stripe\Resources\StripeEvents;
use Integrations\Adapters\Stripe\Resources\StripePaymentIntents;
use Integrations\Adapters\Stripe\Resources\StripePaymentMethods;
use Integrations\Adapters\Stripe\Resources\StripeRefunds;
use Integrations\Adapters\Stripe\Resources\StripeSetupIntents;
use Integrations\Adapters\Stripe\Resources\StripeWebhookEndpoints;
use Integrations\Adapters\Stripe\StripeClient;
use Integrations\Adapters\Stripe\StripeProvider;
use Integrations\Adapters\Tests\TestCase;
use Integrations\Models\Integration;
use Integrations\Testing\CreatesIntegration;
use RuntimeException;
use Stripe\StripeClient as StripeSdkClient;

class StripeClientTest extends TestCase
{
    use CreatesIntegration;

    private function createClient(): StripeClient
    {
        $integration = $this->createIntegration(
            providerKey: 'stripe',
            providerClass: StripeProvider::class,
            credentials: [
                'api_key' => 'sk_test_unit',
                'webhook_secret' => 'whsec_zzz',
            ],
        );

        return new StripeClient($integration, new StripeSdkClient('sk_test_unit'));
    }

    public function test_exposes_payment_intents_resource(): void
    {
        $this->assertInstanceOf(StripePaymentIntents::class, $this->createClient()->paymentIntents());
    }

    public function test_exposes_refunds_resource(): void
    {
        $this->assertInstanceOf(StripeRefunds::class, $this->createClient()->refunds());
    }

    public function test_exposes_customers_resource(): void
    {
        $this->assertInstanceOf(StripeCustomers::class, $this->createClient()->customers());
    }

    public function test_exposes_charges_resource(): void
    {
        $this->assertInstanceOf(StripeCharges::class, $this->createClient()->charges());
    }

    public function test_exposes_disputes_resource(): void
    {
        $this->assertInstanceOf(StripeDisputes::class, $this->createClient()->disputes());
    }

    public function test_exposes_events_resource(): void
    {
        $this->assertInstanceOf(StripeEvents::class, $this->createClient()->events());
    }

    public function test_exposes_webhook_endpoints_resource(): void
    {
        $this->assertInstanceOf(StripeWebhookEndpoints::class, $this->createClient()->webhookEndpoints());
    }

    public function test_exposes_setup_intents_resource(): void
    {
        $this->assertInstanceOf(StripeSetupIntents::class, $this->createClient()->setupIntents());
    }

    public function test_exposes_payment_methods_resource(): void
    {
        $this->assertInstanceOf(StripePaymentMethods::class, $this->createClient()->paymentMethods());
    }

    public function test_resource_accessors_memoize_their_instance(): void
    {
        $client = $this->createClient();

        $this->assertSame($client->paymentIntents(), $client->paymentIntents());
        $this->assertSame($client->refunds(), $client->refunds());
    }

    public function test_get_sdk_client_boots_with_credentials_from_integration(): void
    {
        $client = $this->createClient();

        $this->assertInstanceOf(StripeSdkClient::class, $client->getSdkClient());
    }

    public function test_boot_errors_if_credentials_are_the_wrong_type(): void
    {
        // Bypass CreatesIntegration so we can create a row with no typed
        // credentials: boot() should reject it rather than silently work.
        $integration = Integration::create(['provider' => 'stripe', 'name' => 'Stripe']);

        $client = new StripeClient($integration);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/credentials must be/');
        $client->getSdkClient();
    }
}
