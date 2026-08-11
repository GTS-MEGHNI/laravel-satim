<?php

declare(strict_types=1);

use GtsMeghni\Satim\Enums\SatimLanguage;
use GtsMeghni\Satim\Exceptions\SatimConfigurationException;
use GtsMeghni\Satim\Exceptions\SatimConnectionException;
use GtsMeghni\Satim\Exceptions\SatimValidationException;
use GtsMeghni\Satim\Facades\Satim;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array<string, string>
 */
function sentPayload(): array
{
    /** @var array<string, string> $data */
    $data = [];

    Http::assertSent(function (Request $request) use (&$data): bool {
        $data = $request->data();

        return true;
    });

    return $data;
}

/**
 * @return array<string, mixed>
 */
function sentJsonParams(): array
{
    /** @var array<string, mixed> $decoded */
    $decoded = json_decode(sentPayload()['jsonParams'], true, 512, JSON_THROW_ON_ERROR);

    return $decoded;
}

/**
 * SATIM's own documented success response: errorCode is present and zero.
 */
function fakeRegisterSuccess(): void
{
    Http::fake([
        REGISTER_URL => Http::response([
            'errorCode' => 0,
            'orderId' => 'V721uPPfNNofVQAAABL3',
            'formUrl' => 'https://test.satim.dz/payment/epg/merchants/merchantsatim/payment.html?mdOrder=V721uPPfNNofVQAAABL3&language=fr',
        ]),
    ]);
}

it('returns the order id and form url on success', function () {
    fakeRegisterSuccess();

    $result = Satim::register(
        orderNumber: 'K9m2X7qL4P',
        amount: 5966.56,
    );

    expect($result->successful())->toBeTrue()
        ->and($result->failed())->toBeFalse()
        ->and($result->orderId)->toBe('V721uPPfNNofVQAAABL3')
        ->and($result->formUrl)->toContain('mdOrder=V721uPPfNNofVQAAABL3')
        ->and($result->errorCode)->toBe('0');   // SATIM sends 0 on success too
});

it('posts a form encoded body to the register endpoint', function () {
    fakeRegisterSuccess();

    Satim::register('K9m2X7qL4P', 5000);

    Http::assertSent(fn (Request $request): bool => $request->url() === REGISTER_URL
        && $request->method() === 'POST'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
});

it('sends the credentials and request defaults from config', function () {
    fakeRegisterSuccess();

    Satim::register('K9m2X7qL4P', 5000);

    expect(sentPayload())
        ->userName->toBe('merchant-user')
        ->password->toBe('merchant-secret')
        ->currency->toBe('012')
        ->language->toBe('FR')
        ->orderNumber->toBe('K9m2X7qL4P')
        ->returnUrl->toBe('https://merchant.test/satim/return')
        ->failUrl->toBe('https://merchant.test/satim/fail');
});

it('converts the amount from dinars to centimes', function (int|float|string $amount, string $expected) {
    fakeRegisterSuccess();

    Satim::register('K9m2X7qL4P', $amount);

    expect(sentPayload()['amount'])->toBe($expected);
})->with([
    [5966.56, '596656'],
    [5000, '500000'],
    ['806.5', '80650'],
    [50, '5000'],
    ['50.00', '5000'],
]);

it('rejects an amount below the 50 DA minimum', function (int|float|string $amount) {
    fakeRegisterSuccess();

    expect(fn () => Satim::register('K9m2X7qL4P', $amount))
        ->toThrow(SatimValidationException::class, 'below the gateway minimum');

    Http::assertNothingSent();
})->with([49.99, 0, '10']);

it('rejects a non numeric amount', function () {
    fakeRegisterSuccess();

    expect(fn () => Satim::register('K9m2X7qL4P', 'abc'))
        ->toThrow(SatimValidationException::class, 'it must be numeric');

    Http::assertNothingSent();
});

it('rejects an order number that is not exactly 10 alphanumeric characters', function (string $orderNumber) {
    fakeRegisterSuccess();

    expect(fn () => Satim::register($orderNumber, 5000))
        ->toThrow(SatimValidationException::class, 'exactly 10 alphanumeric characters');

    Http::assertNothingSent();
})->with(['K9m2X7qL4', 'K9m2X7qL4PP', 'K9m2-X7qL4', '', 'K9m2 X7qL4']);

it('forces the configured terminal id through jsonParams', function () {
    fakeRegisterSuccess();

    Satim::register('K9m2X7qL4P', 5000);

    expect(sentJsonParams())->toHaveKey('force_terminal_id', 'E0123456789');
});

it('defaults udf1 to the order number', function () {
    fakeRegisterSuccess();

    Satim::register('K9m2X7qL4P', 5000);

    expect(sentJsonParams())->toHaveKey('udf1', 'K9m2X7qL4P');
});

it('allows udf1 to be overridden', function () {
    fakeRegisterSuccess();

    Satim::register('K9m2X7qL4P', 5000, udf1: 'Bill00001');

    expect(sentJsonParams())->toHaveKey('udf1', 'Bill00001');
});

it('sends the optional udf slots and funding type indicator only when given', function () {
    fakeRegisterSuccess();

    Satim::register(
        orderNumber: 'K9m2X7qL4P',
        amount: 5000,
        udf5: 'invoice-7788',
        fundingTypeIndicator: 'CP',
    );

    expect(sentJsonParams())
        ->toHaveKey('udf5', 'invoice-7788')
        ->toHaveKey('fundingTypeIndicator', 'CP')
        ->not->toHaveKey('udf2')
        ->not->toHaveKey('udf3')
        ->not->toHaveKey('udf4');
});

it('rejects a udf value longer than 20 characters', function () {
    fakeRegisterSuccess();

    expect(fn () => Satim::register('K9m2X7qL4P', 5000, udf2: str_repeat('a', 21)))
        ->toThrow(SatimValidationException::class, '[udf2] is 21 characters long');

    Http::assertNothingSent();
});

it('omits the description when it was not provided', function () {
    fakeRegisterSuccess();

    Satim::register('K9m2X7qL4P', 5000);

    expect(sentPayload())->not->toHaveKey('description');
});

it('sends the redirect urls from config on every order', function () {
    config()->set('satim.return_url', 'https://merchant.test/configured/return');
    config()->set('satim.fail_url', 'https://merchant.test/configured/fail');
    fakeRegisterSuccess();

    Satim::register('K9m2X7qL4P', 5000);

    expect(sentPayload())
        ->returnUrl->toBe('https://merchant.test/configured/return')
        ->failUrl->toBe('https://merchant.test/configured/fail');
});

it('refuses to register when a redirect url is not configured', function (string $key) {
    config()->set("satim.$key", null);
    fakeRegisterSuccess();

    expect(fn () => Satim::register('K9m2X7qL4P', 5000))
        ->toThrow(SatimConfigurationException::class, "[satim.$key] is required but missing");

    Http::assertNothingSent();
})->with(['return_url', 'fail_url']);

it('rejects a configured redirect url that is not absolute', function () {
    config()->set('satim.return_url', '/relative/return');
    fakeRegisterSuccess();

    expect(fn () => Satim::register('K9m2X7qL4P', 5000))
        ->toThrow(SatimConfigurationException::class, 'absolute URL SATIM can redirect to');

    Http::assertNothingSent();
});

it('always sends the fail url, which SATIM marks as required', function () {
    fakeRegisterSuccess();

    Satim::register('K9m2X7qL4P', 5000);

    expect(sentPayload()['failUrl'])->toBe('https://merchant.test/satim/fail');
});

it('sends the description when provided', function () {
    fakeRegisterSuccess();

    Satim::register(
        orderNumber: 'K9m2X7qL4P',
        amount: 5000,
        description: 'Order K9m2X7qL4P',
    );

    expect(sentPayload())
        ->failUrl->toBe('https://merchant.test/satim/fail')
        ->description->toBe('Order K9m2X7qL4P');
});

it('accepts the language as an enum or a string', function (SatimLanguage|string $language, string $expected) {
    fakeRegisterSuccess();

    Satim::register('K9m2X7qL4P', 5000, language: $language);

    expect(sentPayload()['language'])->toBe($expected);
})->with([
    [SatimLanguage::AR, 'AR'],
    ['en', 'EN'],
    ['FR', 'FR'],
]);

it('rejects an unsupported language', function () {
    fakeRegisterSuccess();

    expect(fn () => Satim::register('K9m2X7qL4P', 5000, language: 'ES'))
        ->toThrow(SatimValidationException::class, 'supported languages are AR, FR and EN');

    Http::assertNothingSent();
});

it('reports a gateway rejection as a failed result instead of throwing', function () {
    Http::fake([
        REGISTER_URL => Http::response([
            'errorCode' => '1',
            'errorMessage' => 'Order with given order number is already processed',
        ]),
    ]);

    $result = Satim::register('K9m2X7qL4P', 5000);

    expect($result->successful())->toBeFalse()
        ->and($result->failed())->toBeTrue()
        ->and($result->orderId)->toBeNull()
        ->and($result->formUrl)->toBeNull()
        ->and($result->errorCode)->toBe('1')
        ->and($result->errorMessage)->toBe('Order with given order number is already processed');
});

it('normalises a numeric error code to a string', function () {
    Http::fake([REGISTER_URL => Http::response(['errorCode' => 7, 'errorMessage' => 'System error'])]);

    expect(Satim::register('K9m2X7qL4P', 5000)->errorCode)->toBe('7');
});

it('exposes the raw payloads for persistence with the credentials redacted', function () {
    fakeRegisterSuccess();

    $result = Satim::register('K9m2X7qL4P', 5000);

    expect($result->rawRequest)
        ->userName->toBe('[REDACTED]')
        ->password->toBe('[REDACTED]')
        ->orderNumber->toBe('K9m2X7qL4P')
        ->amount->toBe('500000')
        ->and($result->rawResponse)->toHaveKey('orderId', 'V721uPPfNNofVQAAABL3');
});

it('throws a connection exception when the gateway is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => Satim::register('K9m2X7qL4P', 5000))
        ->toThrow(SatimConnectionException::class, 'Operation timed out');
});

it('throws a connection exception on a server error status', function () {
    Http::fake([REGISTER_URL => Http::response('Service Unavailable', 503)]);

    expect(fn () => Satim::register('K9m2X7qL4P', 5000))
        ->toThrow(SatimConnectionException::class, 'responded with HTTP status 503');
});

it('throws a connection exception when the response is not a json object', function () {
    Http::fake([REGISTER_URL => Http::response('<html>maintenance</html>')]);

    expect(fn () => Satim::register('K9m2X7qL4P', 5000))
        ->toThrow(SatimConnectionException::class, 'did not return a JSON object');
});

it('refuses to call the gateway when the configuration is incomplete', function () {
    config()->set('satim.base_url', null);
    fakeRegisterSuccess();

    expect(fn () => Satim::register('K9m2X7qL4P', 5000))
        ->toThrow(SatimConfigurationException::class, '[satim.base_url] is required but missing');

    Http::assertNothingSent();
});
