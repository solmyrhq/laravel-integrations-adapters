<?php

declare(strict_types=1);

namespace Integrations\Adapters\Stripe\Enums;

/**
 * How a saved payment method will be used after the intent that saves it.
 * The value is Stripe's `setup_future_usage` on a PaymentIntent and `usage`
 * on a SetupIntent. See https://docs.stripe.com/api/payment_intents/create#create_payment_intent-setup_future_usage.
 */
enum StripeSetupFutureUsage: string
{
    case OffSession = 'off_session';
    case OnSession = 'on_session';
}
