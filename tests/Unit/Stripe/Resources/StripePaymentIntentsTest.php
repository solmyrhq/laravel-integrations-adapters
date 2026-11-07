<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Unit\Stripe\Resources;

use Integrations\Adapters\Stripe\Enums\StripeSetupFutureUsage;
use Integrations\Adapters\Tests\Support\MakesRecordingStripeClient;
use Integrations\Adapters\Tests\TestCase;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Stripe\PaymentIntent;

class StripePaymentIntentsTest extends TestCase
{
    use MakesRecordingStripeClient;

    private const array INTENT = ['id' => 'pi_123', 'object' => 'payment_intent', 'status' => 'requires_payment_method'];

    public function test_create_enables_automatic_payment_methods_by_default(): void
    {
        [$client, $sdk] = $this->recordingClient(self::INTENT);

        $intent = $client->paymentIntents()->create(amount: 24000, currency: 'usd');

        $this->assertInstanceOf(PaymentIntent::class, $intent);
        $request = $sdk->onlyRequest();
        $this->assertSame('post', $request['method']);
        $this->assertSame('/v1/payment_intents', $request['path']);
        $this->assertSame([
            'amount' => 24000,
            'currency' => 'usd',
            'automatic_payment_methods' => ['enabled' => true],
        ], $request['params']);
    }

    public function test_create_saves_the_method_for_off_session_use(): void
    {
        [$client, $sdk] = $this->recordingClient(self::INTENT);

        $client->paymentIntents()->create(
            amount: 1900,
            currency: 'usd',
            customer: 'cus_1',
            automaticPaymentMethods: false,
            allowedPaymentMethodTypes: ['card'],
            setupFutureUsage: StripeSetupFutureUsage::OffSession,
        );

        $this->assertSame([
            'amount' => 1900,
            'currency' => 'usd',
            'allowed_payment_method_types' => ['card'],
            'customer' => 'cus_1',
            'setup_future_usage' => 'off_session',
        ], $sdk->onlyRequest()['params']);
    }

    public function test_create_charges_a_saved_method_off_session(): void
    {
        [$client, $sdk] = $this->recordingClient(self::INTENT);

        $client->paymentIntents()->create(
            amount: 1900,
            currency: 'usd',
            customer: 'cus_1',
            automaticPaymentMethods: false,
            idempotencyKey: 'charge:1:confirm:1',
            paymentMethod: 'pm_1',
            allowedPaymentMethodTypes: ['card'],
            confirm: true,
            offSession: true,
        );

        $request = $sdk->onlyRequest();
        $this->assertSame([
            'amount' => 1900,
            'currency' => 'usd',
            'allowed_payment_method_types' => ['card'],
            'customer' => 'cus_1',
            'payment_method' => 'pm_1',
            'confirm' => true,
            'off_session' => true,
        ], $request['params']);
        $this->assertSame(['idempotency_key' => 'charge:1:confirm:1'], $request['opts']);
    }

    public function test_create_passes_a_return_url_when_it_confirms(): void
    {
        [$client, $sdk] = $this->recordingClient(self::INTENT);

        $client->paymentIntents()->create(
            amount: 1900,
            currency: 'usd',
            paymentMethod: 'pm_1',
            confirm: true,
            returnUrl: 'https://example.com/done',
        );

        $params = $sdk->onlyRequest()['params'];
        $this->assertIsArray($params);
        $this->assertSame('https://example.com/done', $params['return_url']);
        $this->assertTrue($params['confirm']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string}>
     */
    public static function refusedCreations(): iterable
    {
        yield 'off session without confirming' => [['paymentMethod' => 'pm_1', 'offSession' => true], 'Pass confirm: true'];
        yield 'return url without confirming' => [['returnUrl' => 'https://example.com'], 'Pass confirm: true'];
        yield 'confirming without a method' => [['confirm' => true], 'Pass a paymentMethod'];
        yield 'types with automatic methods' => [['allowedPaymentMethodTypes' => ['card']], 'automaticPaymentMethods: false'];
        yield 'an empty list of types' => [['automaticPaymentMethods' => false, 'allowedPaymentMethodTypes' => []], 'cannot be empty'];
        yield 'an empty method id' => [['paymentMethod' => ''], 'cannot be empty'];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    #[DataProvider('refusedCreations')]
    public function test_create_refuses_parameters_stripe_would_reject(array $arguments, string $message): void
    {
        [$client, $sdk] = $this->recordingClient(self::INTENT);

        try {
            $client->paymentIntents()->create(1900, 'usd', ...$arguments);
            $this->fail('create() accepted parameters Stripe would reject.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }

        $this->assertSame([], $sdk->requests);
    }

    public function test_confirm_retries_a_saved_method_off_session(): void
    {
        [$client, $sdk] = $this->recordingClient(self::INTENT);

        $client->paymentIntents()->confirm(
            'pi_123',
            paymentMethod: 'pm_1',
            idempotencyKey: 'charge:1:confirm:2',
            offSession: true,
        );

        $request = $sdk->onlyRequest();
        $this->assertSame('/v1/payment_intents/pi_123/confirm', $request['path']);
        $this->assertSame(['payment_method' => 'pm_1', 'off_session' => true], $request['params']);
        $this->assertSame(['idempotency_key' => 'charge:1:confirm:2'], $request['opts']);
    }

    public function test_confirm_passes_a_return_url_and_future_usage(): void
    {
        [$client, $sdk] = $this->recordingClient(self::INTENT);

        $client->paymentIntents()->confirm(
            'pi_123',
            returnUrl: 'https://example.com/done',
            setupFutureUsage: StripeSetupFutureUsage::OffSession,
        );

        $this->assertSame(
            ['return_url' => 'https://example.com/done', 'setup_future_usage' => 'off_session'],
            $sdk->onlyRequest()['params'],
        );
    }
}
