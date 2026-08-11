<?php

declare(strict_types=1);

use GtsMeghni\Satim\Enums\SatimLanguage;
use GtsMeghni\Satim\Exceptions\SatimConnectionException;
use GtsMeghni\Satim\Exceptions\SatimValidationException;
use GtsMeghni\Satim\Facades\Satim;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @param  array<string, mixed>  $response
 */
function fakeRefund(array $response = ['errorCode' => 0]): void
{
    Http::fake([REFUND_URL => Http::response($response)]);
}

/**
 * @return array<string, string>
 */
function sentRefundPayload(): array
{
    /** @var array<string, string> $data */
    $data = [];

    Http::assertSent(function (Request $request) use (&$data): bool {
        $data = $request->data();

        return true;
    });

    return $data;
}

it('posts to the refund endpoint, which is not under the public path', function () {
    fakeRefund();

    Satim::refund(ORDER_ID, 200);

    Http::assertSent(fn (Request $request): bool => $request->url() === REFUND_URL
        && $request->method() === 'POST'
        && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded'));
});

it('sends only the parameters SATIM documents as required, plus the language', function () {
    fakeRefund();

    Satim::refund(ORDER_ID, 200);

    expect(sentRefundPayload())->toBe([
        'userName' => 'merchant-user',
        'password' => 'merchant-secret',
        'orderId' => ORDER_ID,
        'amount' => '20000',
        'language' => 'FR',
    ]);
});

it('converts the refund amount from dinars to centimes', function (int|float|string $amount, string $expected) {
    fakeRefund();

    Satim::refund(ORDER_ID, $amount);

    expect(sentRefundPayload()['amount'])->toBe($expected);
})->with([
    [5000, '500000'],
    [806.5, '80650'],
    ['200', '20000'],
    [50, '5000'],
]);

it('reports a successful refund', function (mixed $errorCode) {
    fakeRefund(['errorCode' => $errorCode]);

    $result = Satim::refund(ORDER_ID, 200);

    expect($result->successful())->toBeTrue()
        ->and($result->failed())->toBeFalse()
        ->and($result->errorCode)->toBe('0')
        ->and($result->orderId)->toBe(ORDER_ID)
        ->and($result->amountInCentimes)->toBe(20000)
        ->and($result->amount)->toBe(200.0);
})->with([0, '0']);

it('reports a refused refund without throwing', function (mixed $errorCode, string $message) {
    fakeRefund(['errorCode' => $errorCode, 'errorMessage' => $message]);

    $result = Satim::refund(ORDER_ID, 200);

    expect($result->failed())->toBeTrue()
        ->and($result->successful())->toBeFalse()
        ->and($result->errorCode)->toBe((string) $errorCode)
        ->and($result->errorMessage)->toBe($message);
})->with([
    [5, 'Invalid amount.'],
    [6, 'Unregistered OrderId.'],
    [7, 'Payment must be in a correct state.'],
]);

it('treats a response with no error code as a failure', function () {
    fakeRefund([]);

    $result = Satim::refund(ORDER_ID, 200);

    expect($result->successful())->toBeFalse()
        ->and($result->errorCode)->toBeNull();
});

it('rejects an amount below the 50 DA minimum without calling the gateway', function () {
    fakeRefund();

    expect(fn () => Satim::refund(ORDER_ID, 10))
        ->toThrow(SatimValidationException::class, 'below the gateway minimum');

    Http::assertNothingSent();
});

it('rejects a non numeric amount without calling the gateway', function () {
    fakeRefund();

    expect(fn () => Satim::refund(ORDER_ID, 'abc'))
        ->toThrow(SatimValidationException::class, 'it must be numeric');

    Http::assertNothingSent();
});

it('rejects a blank order id without calling the gateway', function () {
    fakeRefund();

    expect(fn () => Satim::refund('   ', 200))
        ->toThrow(SatimValidationException::class, 'order id is required');

    Http::assertNothingSent();
});

it('accepts the language as an enum or a string', function (SatimLanguage|string $language, string $expected) {
    fakeRefund();

    Satim::refund(ORDER_ID, 200, language: $language);

    expect(sentRefundPayload()['language'])->toBe($expected);
})->with([
    [SatimLanguage::AR, 'AR'],
    ['en', 'EN'],
]);

it('exposes the raw payloads for persistence with the credentials redacted', function () {
    fakeRefund(['errorCode' => 0]);

    $result = Satim::refund(ORDER_ID, 200);

    expect($result->rawRequest)
        ->userName->toBe('[REDACTED]')
        ->password->toBe('[REDACTED]')
        ->orderId->toBe(ORDER_ID)
        ->amount->toBe('20000')
        ->and($result->rawResponse)->toBe(['errorCode' => 0]);
});

it('supports several partial refunds against one order', function () {
    fakeRefund();

    $first = Satim::refund(ORDER_ID, 300);
    $second = Satim::refund(ORDER_ID, 200);

    expect($first->successful())->toBeTrue()
        ->and($first->amountInCentimes)->toBe(30000)
        ->and($second->successful())->toBeTrue()
        ->and($second->amountInCentimes)->toBe(20000);

    Http::assertSentCount(2);
});

it('throws when the gateway is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => Satim::refund(ORDER_ID, 200))
        ->toThrow(SatimConnectionException::class, 'Operation timed out');
});

it('throws on a server error status', function () {
    Http::fake([REFUND_URL => Http::response('Service Unavailable', 503)]);

    expect(fn () => Satim::refund(ORDER_ID, 200))
        ->toThrow(SatimConnectionException::class, 'responded with HTTP status 503');
});
