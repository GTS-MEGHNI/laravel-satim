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
    */

    'audit' => [
        'enabled' => env('SATIM_AUDIT', true),

        'table' => 'satim_calls',
    ],

];
