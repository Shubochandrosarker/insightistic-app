<?php

/*
|--------------------------------------------------------------------------
| Billing
|--------------------------------------------------------------------------
| Active billing provider for every paid checkout.
| Stripe is the only payment gateway. AppSumo is a marketplace redemption
| channel, not a payment provider in this application.
|
*/

return [
    'provider' => 'stripe',

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
    ],
];
