<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway Base URL
    |--------------------------------------------------------------------------
    |
    | The root of the SATIM REST API, without a trailing slash and without the
    | endpoint segment. The package appends "/register.do" and friends to it.
    |
    | There is deliberately no default: the production host is NOT the test
    | host, so a fallback value would risk sending live money to the wrong
    | gateway. The value must use HTTPS.
    |
    | Test: https://test2.satim.dz/payment/rest
    |
    */

    'base_url' => env('SATIM_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Merchant Credentials
    |--------------------------------------------------------------------------
    |
    | Issued by SATIM during registration. The terminal id is sent to the
    | gateway as "force_terminal_id" inside the jsonParams payload.
    |
    */

    'username' => env('SATIM_USER'),

    'password' => env('SATIM_PASSWORD'),

    'terminal_id' => env('SATIM_TERMINAL_ID'),

    /*
    |--------------------------------------------------------------------------
    | Redirect URLs
    |--------------------------------------------------------------------------
    |
    | Where SATIM sends the customer back to. Both are required by the gateway,
    | and both must be absolute URLs it can reach.
    |
    | They are configured here and only here: register() sends these values and
    | takes no URL arguments, so the gateway can only ever redirect a customer
    | to an address this environment declared. Carry your own reference through
    | the order number instead of through the URL.
    |
    | Confirm the outcome on BOTH: a customer landing on the fail URL is not
    | proof of failure, only acknowledge() decides.
    |
    */

    'return_url' => env('SATIM_RETURN_URL'),

    'fail_url' => env('SATIM_FAIL_URL'),

    /*
    |--------------------------------------------------------------------------
    | Request Defaults
    |--------------------------------------------------------------------------
    |
    | The ISO 4217 currency code ("012" for DZD) and the ISO 639-1 language
    | used for the hosted payment page. Supported languages: AR, FR, EN.
    |
    */

    'currency' => env('SATIM_CURRENCY', '012'),

    'language' => env('SATIM_LANGUAGE', 'FR'),

    /*
    | Seconds to wait for the gateway to answer, once connected. Keep this
    | generous: cutting a request off early does not undo it, so a short value
    | risks money moving without your application ever learning the outcome.
    */

    'timeout' => 30,

    /*
    | Seconds to wait for the connection itself. Failing here is safe, because
    | nothing reached the gateway, so this can be short.
    */

    'connect_timeout' => 10,

    /*
    |--------------------------------------------------------------------------
    | Receipt
    |--------------------------------------------------------------------------
    |
    | Path of the SATIM logo shown beside the hotline message. It defaults to the
    | logo shipped with this package, so publish the assets to serve it:
    |
    |     php artisan vendor:publish --tag="satim-assets"
    |
    | Point this at your own copy if you serve it from elsewhere. SATIM requires
    | the logo to appear beside the hotline message and never on its own, so
    | blanking this makes building a receipt fail rather than producing the
    | message alone.
    |
    */

    'receipt' => [
        'logo' => '/vendor/satim/satim.png',
    ],

    /*
    |--------------------------------------------------------------------------
    | Audit Trail
    |--------------------------------------------------------------------------
    |
    | Records one row per gateway call: what was sent, what came back, and what
    | it meant. On by default, because SATIM may ask months later what exactly
    | was exchanged for a payment, and the evidence has to exist by then.
    |
    | The package ships the migration, so `php artisan migrate` creates the
    | table. Publish it only if you want to own the schema, and tell the package
    | to stop running its own copy so the two do not both create the table:
    |
    |     php artisan vendor:publish --tag="satim-migrations"
    |     SatimServiceProvider::ignoreMigrations();
    |
    | Set SATIM_AUDIT=false to switch the recording off. The gateway calls fire
    | events either way, so you can listen to those and store the trail
    | yourself instead. See the readme.
    |
    | How many days to keep a row, applied by Laravel's own prune command:
    |
    |     Schedule::command('model:prune', [
    |         '--model' => [GtsMeghni\Satim\Models\SatimCall::class],
    |     ])->daily();
    |
    | It defaults to ten years, the period article 12 of the Algerian code de
    | commerce sets for commercial books and documents. Nothing is deleted until
    | you schedule that command, and setting this to null keeps everything
    | forever. Do not shorten it without asking whoever answers for your
    | accounting: this is the evidence of what was exchanged for a payment, and
    | a dispute long outlives a checkout.
    |
    */

    'audit' => [
        'enabled' => env('SATIM_AUDIT', true),

        'table' => 'satim_calls',

        'retention' => env('SATIM_AUDIT_RETENTION', 365 * 10),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reconciliation
    |--------------------------------------------------------------------------
    |
    | A customer whose browser never reaches the return URL leaves an order
    | nobody ever confirmed, even though the card may have been charged. The
    | `satim:reconcile` command asks the gateway what became of those orders and
    | fires SatimOrderReconciled with the answer. Schedule it:
    |
    |     Schedule::command('satim:reconcile')->everyFifteenMinutes()->withoutOverlapping();
    |
    | It reads the audit trail to find them, so it does nothing while the trail
    | is switched off.
    |
    | "after" is how long an order must have gone unconfirmed first. SATIM gives
    | the customer 20 minutes on the payment page to submit their card details,
    | so 30 leaves a margin: below 20 the command would be asking about orders a
    | customer is still in the middle of paying.
    |
    | "within" stops the command asking about orders old enough that no answer
    | will ever change, so an order SATIM never resolves is not polled forever.
    |
    */

    'reconcile' => [
        'after' => 30,

        'within' => 60 * 24 * 7,

        'limit' => 100,
    ],

];
