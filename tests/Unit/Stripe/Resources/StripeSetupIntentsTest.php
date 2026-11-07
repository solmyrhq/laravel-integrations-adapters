<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Stripe\Resources;

use Integrations\Adapters\Stripe\Enums\StripeSetupFutureUsage;
use Integrations\Adapters\Tests\Support\MakesRecordingStripeClient;
use Integrations\Adapters\Tests\TestCase;
use InvalidArgumentException;
use Stripe\SetupIntent;

class StripeSetupIntentsTest extends TestCase
{
    use MakesRecordingStripeClient;

    private const array INTENT = ['id' => 'seti_123', 'object' => 'setup_intent', 'status' => 'requires_payment_method'];

    public function test_create_saves_a_method_for_off_session_use_by_default(): void
    {
        [$client, $sdk] = $this->recordingClient(self::INTENT);

        $intent = $client->setupIntents()->create('cus_1', idempotencyKey: 'card:1');

        $this->assertInstanceOf(SetupIntent::class, $intent);
        $request = $sdk->onlyRequest();
        $this->assertSame('post', $request['method']);
        $this->assertSame('/v1/setup_intents', $request['path']);
        $this->assertSame([
            'customer' => 'cus_1',
            'usage' => 'off_session',
            'automatic_payment_methods' => ['enabled' => true],
        ], $request['params']);
        $this->assertSame(['idempotency_key' => 'card:1'], $request['opts']);
    }

    public function test_create_takes_a_list_of_method_types_and_metadata(): void
    {
        [$client, $sdk] = $this->recordingClient(self::INTENT);

        $client->setupIntents()->create(
            'cus_1',
            usage: StripeSetupFutureUsage::OnSession,
            automaticPaymentMethods: false,
            allowedPaymentMethodTypes: ['card'],
            description: 'Card for renewals',
            metadata: ['subscription_id' => 'sub-1'],
        );

        $this->assertSame([
            'customer' => 'cus_1',
            'usage' => 'on_session',
            'allowed_payment_method_types' => ['card'],
            'description' => 'Card for renewals',
            'metadata' => ['subscription_id' => 'sub-1'],
        ], $sdk->onlyRequest()['params']);
    }

    public function test_create_refuses_an_empty_customer(): void
    {
        [$client, $sdk] = $this->recordingClient(self::INTENT);

        $this->expectException(InvalidArgumentException::class);

        try {
            $client->setupIntents()->create('');
        } finally {
            $this->assertSame([], $sdk->requests);
        }
    }

    public function test_retrieve_reads_one_intent(): void
    {
        [$client, $sdk] = $this->recordingClient(self::INTENT);

        $client->setupIntents()->retrieve('seti_123');

        $request = $sdk->onlyRequest();
        $this->assertSame('get', $request['method']);
        $this->assertSame('/v1/setup_intents/seti_123', $request['path']);
    }

    public function test_cancel_sends_the_reason(): void
    {
        [$client, $sdk] = $this->recordingClient(self::INTENT);

        $client->setupIntents()->cancel('seti_123', 'abandoned', 'cancel:1');

        $request = $sdk->onlyRequest();
        $this->assertSame('/v1/setup_intents/seti_123/cancel', $request['path']);
        $this->assertSame(['cancellation_reason' => 'abandoned'], $request['params']);
        $this->assertSame(['idempotency_key' => 'cancel:1'], $request['opts']);
    }
}
