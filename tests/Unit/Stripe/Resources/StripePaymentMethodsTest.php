<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Stripe\Resources;

use Integrations\Adapters\Tests\Support\MakesRecordingStripeClient;
use Integrations\Adapters\Tests\TestCase;
use Stripe\PaymentMethod;

class StripePaymentMethodsTest extends TestCase
{
    use MakesRecordingStripeClient;

    private const array METHOD = ['id' => 'pm_123', 'object' => 'payment_method', 'type' => 'card'];

    public function test_retrieve_reads_one_method(): void
    {
        [$client, $sdk] = $this->recordingClient(self::METHOD);

        $method = $client->paymentMethods()->retrieve('pm_123');

        $this->assertInstanceOf(PaymentMethod::class, $method);
        $request = $sdk->onlyRequest();
        $this->assertSame('get', $request['method']);
        $this->assertSame('/v1/payment_methods/pm_123', $request['path']);
    }

    public function test_detach_posts_with_the_idempotency_key(): void
    {
        [$client, $sdk] = $this->recordingClient(self::METHOD);

        $client->paymentMethods()->detach('pm_123', 'detach:1');

        $request = $sdk->onlyRequest();
        $this->assertSame('post', $request['method']);
        $this->assertSame('/v1/payment_methods/pm_123/detach', $request['path']);
        $this->assertSame(['idempotency_key' => 'detach:1'], $request['opts']);
    }

    public function test_list_for_customer_reads_the_customers_methods(): void
    {
        [$client, $sdk] = $this->recordingClient(self::METHOD);

        $methods = $client->paymentMethods()->listForCustomer('cus_1', type: 'card', limit: 10);

        $this->assertCount(1, $methods->data);
        $request = $sdk->onlyRequest();
        $this->assertSame('get', $request['method']);
        $this->assertSame('/v1/customers/cus_1/payment_methods', $request['path']);
        $this->assertSame(['type' => 'card', 'limit' => 10], $request['params']);
    }
}
