<?php

declare(strict_types=1);

namespace Integrations\Adapters\Tests\Support;

use Integrations\Adapters\Stripe\StripeClient;
use Integrations\Adapters\Stripe\StripeProvider;
use Integrations\Testing\CreatesIntegration;

trait MakesRecordingStripeClient
{
    use CreatesIntegration;

    /**
     * @param  array<string, mixed>  $response
     * @return array{StripeClient, RecordingStripeSdkClient}
     */
    private function recordingClient(array $response): array
    {
        $integration = $this->createIntegration(
            providerKey: 'stripe',
            providerClass: StripeProvider::class,
            credentials: [
                'api_key' => 'sk_test_unit',
                'webhook_secret' => 'whsec_zzz',
            ],
        );
        $sdk = new RecordingStripeSdkClient($response);

        return [new StripeClient($integration, $sdk), $sdk];
    }
}
