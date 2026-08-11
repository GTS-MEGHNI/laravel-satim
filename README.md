<div align="center">
    <h1>Laravel Satim</h1>
</div>

<p align="center">
    <a href="https://packagist.org/packages/gts-meghni/laravel-satim"><img src="https://img.shields.io/packagist/v/gts-meghni/laravel-satim.svg?style=flat-square" alt="Packagist"></a>
    <a href="https://packagist.org/packages/gts-meghni/laravel-satim"><img src="https://img.shields.io/packagist/php-v/gts-meghni/laravel-satim.svg?style=flat-square" alt="PHP from Packagist"></a>
    <a href="https://packagist.org/packages/gts-meghni/laravel-satim"><img src="https://badge.laravel.cloud/badge/gts-meghni/laravel-satim?style=flat" alt="Laravel versions"></a>
    <a href="https://github.com/gts-meghni/laravel-satim/actions"><img alt="GitHub Workflow Status (main)" src="https://img.shields.io/github/actions/workflow/status/gts-meghni/laravel-satim/tests.yml?branch=main&label=Tests&style=flat-square"></a>
    <a href="https://packagist.org/packages/gts-meghni/laravel-satim"><img src="https://img.shields.io/packagist/dt/gts-meghni/laravel-satim.svg?style=flat-square" alt="Total Downloads"></a>
</p>

Laravel package for integrating SATIM payment gateway with Laravel applications.

## Installation

You can install the package via Composer:

```bash
composer require gts-meghni/laravel-satim
```

The package ships the [audit trail](#audit-trail) migration, so run:

```bash
php artisan migrate
```

You may publish all of the package's resources at once:

```bash
php artisan vendor:publish --tag="satim"
```

The migration is not part of that tag, since the package already runs it. Publish it only to
[own the schema](#audit-trail). Or, you may publish each resource individually:

### Publishing the Configuration File

```bash
php artisan vendor:publish --tag="satim-config"
```

### Publishing the Translations

```bash
php artisan vendor:publish --tag="satim-lang"
```

### Publishing the Public Assets

```bash
php artisan vendor:publish --tag="satim-assets"
```

## Configuration

Add your SATIM credentials to `.env`:

```dotenv
SATIM_BASE_URL=https://test2.satim.dz/payment/rest
SATIM_USER=your-merchant-user
SATIM_PASSWORD=your-merchant-password
SATIM_TERMINAL_ID=E0123456789
SATIM_RETURN_URL=https://example.test/satim/return
SATIM_FAIL_URL=https://example.test/satim/fail
```

> [!IMPORTANT]
> `SATIM_BASE_URL` has no default and must use HTTPS. The production host is **not**
> `test2.satim.dz`; obtain it from SATIM and set it explicitly per environment. Give it the
> REST root, without a trailing slash and without the endpoint segment.

SATIM requires both redirect URLs on every order, and both must be absolute URLs the gateway can
reach. They are configured here and only here: `register()` sends the configured values and takes
no URL arguments, so the gateway can only ever redirect a customer to an address this environment
declared. A missing or relative URL raises `SatimConfigurationException`.

Optional: `SATIM_CURRENCY` (default `012`, DZD) and `SATIM_LANGUAGE` (default `FR`, one of `AR`, `FR`, `EN`).

Verify the configuration as part of every deploy:

```bash
php artisan satim:check
```

It prints the resolved settings with the password masked and exits non-zero when a value is
missing or invalid, so a bad release fails before a customer reaches checkout. The same
validation runs at request time, raising `SatimConfigurationException` and naming the offending
key rather than letting SATIM reject a half-empty request.

## Usage

### Registering an Order

```php
use GtsMeghni\Satim\Facades\Satim;

$result = Satim::register(
    orderNumber: 'K9m2X7qL4P',                     // exactly 10 alphanumeric characters, unique
    amount: 5966.56,                               // in DZD; converted to centimes for SATIM
    description: 'Order K9m2X7qL4P',               // optional
);

if ($result->successful()) {
    // Persist $result->orderId, then hand $result->formUrl to the client, which sends the
    // customer to SATIM's payment page.
    return response()->json([
        'order_id' => $result->orderId,
        'form_url' => $result->formUrl,
    ]);
}

// SATIM rejected the registration; $result->errorCode explains why.
report(new RuntimeException("SATIM register failed: {$result->errorCode}"));
```

`register()` returns a `RegisterResult` and does **not** throw when SATIM rejects the order:
a duplicate order number or a blocked merchant is a normal outcome you should record. It throws
only for problems on your side or the wire:

| Exception | Cause |
| --- | --- |
| `SatimValidationException` | An argument violates a documented SATIM constraint, caught before any HTTP call |
| `SatimConfigurationException` | A required config value is missing or invalid |
| `SatimConnectionException` | The gateway was unreachable, returned an error status, or answered with something other than a JSON object |

`RegisterResult` exposes `successful()`, `failed()`, `orderId`, `formUrl`, `errorCode`,
`errorMessage`, plus `rawRequest` and `rawResponse` for the audit trail. Credentials are
redacted from `rawRequest`, so it is safe to persist.

### Confirming the Payment

When SATIM sends the customer back, confirm the outcome. Do this on both the return and the fail
URL: never trust the redirect alone, and SATIM cancels orders that are not confirmed.

```php
$result = Satim::acknowledge($attempt->gateway_order_id);

if ($result->paid()) {
    // $result->message is ready to store, $result->amount is in dinars.
}
```

Whether the customer paid is decided by three fields read together (`respCode` `00`, `errorCode`
`0` and `orderStatus` `2`), never by one alone. `paid()` applies that rule for you.

| | |
| --- | --- |
| Outcome | `paid()`, `rejected()`, `message` |
| SATIM's own wording | `respCodeDesc`, `actionCodeDescription`, `errorMessage` |
| Codes | `respCode`, `errorCode`, `orderStatus`, `actionCode` |
| Payment | `orderId`, `orderNumber`, `amount` (dinars), `amountInCentimes`, `maskedCard`, `cardholderName`, `expiration`, `approvalCode`, `currency`, `ip` |
| For your records | `rawRequest` (credentials redacted), `rawResponse` |

`message` is the text to persist, following the mapping in SATIM's integration guide. It is
SATIM's own description in every case but one: when an authorisation is reversed
(`orderStatus` `3`) SATIM reports no error of its own, so fixed wording is used instead. SATIM's
original text is still available in `respCodeDesc` and `actionCodeDescription`, so you can show
the customer what the gateway actually said.

That fixed wording ships in the package's language files in French, English and Arabic, and
follows the language you pass to `acknowledge()`, not the application locale, so it matches what
the customer saw on the payment page. Publish `satim-lang` to change it.

### Noticing Refunds and Cancellations

Refunds can be issued from your application (see below) or by staff inside SATIM's back office.
Cancellations are back-office only. Either way, the order status is how you find out:

```php
$result->refunded();     // refunded in the SATIM back office
$result->cancelled();    // authorisation reversed
$result->status;          // the named status, e.g. SatimOrderStatus::Refunded
```

`status` is a `SatimOrderStatus` covering the values SATIM's merchant portal documents, with
`isPaid()`, `isRefunded()`, `isCancelled()`, and `isReturned()` for either of the last two. When
SATIM sends a status outside that list, `status` is `null` and the raw number stays available on
`orderStatus`.

> [!NOTE]
> SATIM documents no endpoint for re-reading an order's state on demand, so there is nothing to
> poll: a refund or cancellation made in the back office shows up the next time you confirm the
> order. Ask SATIM for an order status endpoint if you need to check independently.

### Issuing a Refund

`refund.do` returns money already deposited for an order. Your SATIM user needs the refund
permission, and SATIM refuses a refund on an order that was never charged.

```php
$result = Satim::refund($attempt->gateway_order_id, 200);   // 200 DZD

if ($result->successful()) {
    // $result->amountInCentimes is what SATIM was sent, $result->amount the same in dinars.
}
```

Several partial refunds against one order are allowed, as long as they do not add up to more than
was deposited. Success here is `errorCode` `0` (unlike register, there is no identifier to look
for), and a response the package cannot read counts as a failure rather than a success. A refused
refund is reported through the result, not thrown: check `successful()` and keep both payloads.

`RefundResult` carries `orderId`, `amountInCentimes`, `amount`, `errorCode`, `errorMessage`,
`rawRequest` (credentials redacted), and `rawResponse`.

### Receipts

SATIM requires a receipt after a completed payment, offering print, PDF download, and email as
PDF. The package supplies the **contents**; the page, the PDF, and the email are yours, so you can
brand them freely.

```php
$receipt = Satim::receipt($result);
```

SATIM requires its logo beside the hotline message and never on its own. Both the SATIM logo and
the CIB/EDAHABIA logo ship with the package; publish them once and they are served from your app:

```bash
php artisan vendor:publish --tag="satim-assets"
```

| Asset | Path after publishing | Used for |
| --- | --- | --- |
| `satim.png` | `/vendor/satim/satim.png` | Shown beside the hotline message, on receipts and return pages |
| `cib-edahabia-logo.png` | `/vendor/satim/cib-edahabia-logo.png` | The button that sends the customer to SATIM |

`config('satim.receipt.logo')` already points at the published SATIM logo, so receipts work with no
further setup. Change it if you serve the file from somewhere else. Blanking it makes building a
receipt fail rather than printing the hotline text alone.

`Receipt` carries `orderId`, `orderNumber`, `approvalCode`, `paidAt`, `amount`, `amountInCentimes`,
`currency`, `currencyLabel`, `maskedCard`, `cardholderName`, `message`, `paymentMethod`,
`supportMessage`, `logo`, and `language`, plus `locale()` and `direction()` for rendering.

Two things it enforces:

- **A rejected payment cannot have a receipt.** Asking for one throws, so nothing that looks like
  proof of payment is ever produced for a payment that failed.
- **`paymentMethod` is the single label `CIB/EDAHABIA`.** Both card types use the same terminal and
  the same flow, so there is no card type to detect.

Build the receipt **once** after confirming the payment, store it, and pass the stored date back
later, because SATIM requires a reprint to be the original receipt, not a freshly dated one:

```php
// First time.
$receipt = Satim::receipt($result);
$attempt->update(['receipt_issued_at' => $receipt->paidAt]);

// Any reprint, download, or email afterwards.
$receipt = Satim::receipt($result, paidAt: $attempt->receipt_issued_at);
```

`supportMessage` comes back in the receipt's language, defaulting to the configured one. For a
multilingual site, build one receipt per language and store each.

### Amount, Order Number, and `udf1`

- **Amount** is given in dinars as `int`, `float`, or `string`, and converted to centimes for the
  gateway. Store your business amounts as `DECIMAL(15,2)`; only the API boundary uses centimes.
  SATIM's minimum is 50 DA.
- **Order number** must be exactly 10 alphanumeric characters and unique per attempt. The package
  validates the format; **generating it and guaranteeing uniqueness against your own table is your
  responsibility**. Use a bounded retry on collision. Note that SATIM itself accepts *up to* 10
  characters; the fixed length is the stricter house rule this package enforces.
- **`udf1`** defaults to your `orderNumber` and is echoed back by SATIM on acknowledge, which makes
  it your reconciliation key. Override it with `udf1:` if your reference is something else.
  `udf2`–`udf5` and `fundingTypeIndicator` (`CP` or `698`) are optional; each `udf` value is capped
  at 20 characters.

### Language

The configured language is used by default. Override it per call:

```php
use GtsMeghni\Satim\Enums\SatimLanguage;

Satim::register(..., language: SatimLanguage::AR);
```

## Audit Trail

SATIM may ask you months later what exactly was exchanged for a given payment. The package keeps
that record: one row per gateway call, holding what was sent, what came back, and what it meant.

It is on by default, because the evidence has to exist before it is asked for. The package ships
the migration, so all it needs is:

```bash
php artisan migrate
```

Every call is then recorded automatically, with nothing to add at your call sites.

To switch it off:

```dotenv
SATIM_AUDIT=false
```

To own the schema instead, publish the migration and tell the package to stop running its own copy,
so the table is not created twice:

```bash
php artisan vendor:publish --tag="satim-migrations"
```

```php
use GtsMeghni\Satim\SatimServiceProvider;

// In your AppServiceProvider::register()
SatimServiceProvider::ignoreMigrations();
```

### What Gets Recorded

A row is written **before** the request leaves and completed when the answer arrives. That ordering
is deliberate: a call that times out still leaves evidence, which is the case an audit most needs
and most easily loses, because money may have moved without your application ever hearing back.

Rows are never edited afterwards, which is why there is no `updated_at` column. Credentials are
redacted, so the stored request is safe to keep.

`successful` records the *meaningful* outcome rather than merely that a reply arrived: an order id
for register, a paid transaction for confirm, and a zero error code for refund. A payment that was
confirmed but rejected is therefore complete and not successful.

### Reading an Order's History

```php
use GtsMeghni\Satim\Models\SatimCall;

$state = SatimCall::stateFor($orderId);

$state->registered();             // SATIM accepted the order
$state->acknowledged();           // the outcome was confirmed, whatever it was
$state->paid();                   // the money was deposited
$state->refunded();               // at least one refund went through
$state->refundedInCentimes();     // total returned, across several partial refunds
$state->orderStatus();            // the last status SATIM reported
$state->unanswered();             // calls that never came back, worth chasing with SATIM
$state->calls;                    // every row, oldest first
```

There is no status column anywhere: an order's state is worked out from its rows, so nothing can
drift out of step and a failed call is part of the picture rather than an absence.

The model also offers `forOrder()`, `forOrderNumber()`, `operation()`, `successful()`, and
`completed()` for your own queries. The table is named in `config('satim.audit.table')`.

## What Stays Your Responsibility

The audit trail above covers the gateway exchange. Your own domain stays yours, and the package
holds nothing about it: no customer, no basket, no invoice. Specifically:

- **The order number.** Generating it, and guaranteeing it is unique against your own records
  before calling `register()`. Use a bounded retry on collision.
- **Linking a payment to whatever it is for**, in your own tables. The audit rows carry only SATIM's
  identifiers, so join on `order_number` or `order_id`.
- **Your business amounts**, stored as `DECIMAL(15,2)`. Centimes exist only at the gateway boundary.
- **The receipt page, its PDF, and the email**, from the contents `Satim::receipt()` gives you.

Every result object also exposes `rawRequest` and `rawResponse`, so you can store the payloads in
your own tables instead of, or in addition to, the audit trail.

## Changelog

Please see [CHANGELOG](CHANGELOG.md) for more information on what has changed recently.

## Contributing

Thank you for considering contributing to Laravel Satim! Please review our [contributing guide](.github/CONTRIBUTING.md) to get started.

## Security Vulnerabilities

Please review [our security policy](.github/SECURITY.md) on how to report security vulnerabilities.

## Credits

- [GTS-MEGHNI](https://github.com/gts-meghni)
- [All Contributors](../../contributors)

## License

Laravel Satim is open-sourced software licensed under the [MIT license](LICENSE.md).
