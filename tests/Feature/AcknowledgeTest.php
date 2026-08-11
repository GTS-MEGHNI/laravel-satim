<?php

declare(strict_types=1);

use GtsMeghni\Satim\Enums\SatimLanguage;
use GtsMeghni\Satim\Exceptions\SatimConnectionException;
use GtsMeghni\Satim\Exceptions\SatimValidationException;
use GtsMeghni\Satim\Facades\Satim;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('posts to the public acknowledge endpoint', function () {
    fakeAcknowledge(acknowledgeResponse());

    Satim::acknowledge(ORDER_ID);

    Http::assertSent(fn (Request $request): bool => $request->url() === ACKNOWLEDGE_URL
        && $request->method() === 'POST');
});

it('sends the order id as mdOrder alongside the credentials and language', function () {
    fakeAcknowledge(acknowledgeResponse());

    Satim::acknowledge(ORDER_ID);

    Http::assertSent(fn (Request $request): bool => $request->data() === [
        'userName' => 'merchant-user',
        'password' => 'merchant-secret',
        'mdOrder' => ORDER_ID,
        'language' => 'FR',
    ]);
});

it('rejects a blank order id without calling the gateway', function () {
    fakeAcknowledge(acknowledgeResponse());

    expect(fn () => Satim::acknowledge('   '))
        ->toThrow(SatimValidationException::class, 'order id is required');

    Http::assertNothingSent();
});

it('carries over the order id, which SATIM does not echo back', function () {
    fakeAcknowledge(acknowledgeResponse());

    expect(Satim::acknowledge(ORDER_ID)->orderId)->toBe(ORDER_ID);
});

it('reports a paid transaction', function () {
    fakeAcknowledge(acknowledgeResponse());

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->paid())->toBeTrue()
        ->and($result->rejected())->toBeFalse()
        ->and($result->message)->toBe('Votre paiement a été accepté');
});

it('requires all three fields to agree before reporting a payment as paid', function (array $overrides) {
    fakeAcknowledge(acknowledgeResponse($overrides));

    expect(Satim::acknowledge(ORDER_ID)->paid())->toBeFalse();
})->with([
    'declined resp code' => [['params' => ['respCode' => '05', 'respCode_desc' => 'Refused']]],
    'gateway error' => [['ErrorCode' => '6']],
    'not deposited' => [['OrderStatus' => 1]],
    'missing order status' => [['OrderStatus' => null]],
]);

it('accepts codes sent as numbers instead of strings', function () {
    fakeAcknowledge(acknowledgeResponse([
        'ErrorCode' => 0,
        'OrderStatus' => '2',
        'params' => ['respCode' => '00', 'respCode_desc' => 'Votre paiement a été accepté'],
    ]));

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->paid())->toBeTrue()
        ->and($result->errorCode)->toBe('0')
        ->and($result->orderStatus)->toBe(2);
});

it('uses the required wording when the authorisation was reversed', function (SatimLanguage $language, string $expected) {
    fakeAcknowledge(acknowledgeResponse([
        'OrderStatus' => 3,
        'params' => ['respCode' => '00', 'respCode_desc' => 'Votre paiement a été accepté'],
    ]));

    $result = Satim::acknowledge(ORDER_ID, $language);

    expect($result->rejected())->toBeTrue()
        ->and($result->message)->toBe($expected);
})->with([
    [SatimLanguage::FR, 'Votre transaction a été rejetée'],
    [SatimLanguage::EN, 'Your transaction was rejected'],
    [SatimLanguage::AR, 'تم رفض معاملتك'],
]);

it('still exposes the original SATIM wording when the authorisation was reversed', function () {
    fakeAcknowledge(acknowledgeResponse([
        'OrderStatus' => 3,
        'params' => ['respCode' => '00', 'respCode_desc' => 'Votre paiement a été accepté'],
    ]));

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->message)->toBe('Votre transaction a été rejetée')
        ->and($result->respCodeDesc)->toBe('Votre paiement a été accepté')
        ->and($result->actionCodeDescription)->toBe('Votre paiement a été accepté');
});

it('uses the SATIM wording for any other rejection', function () {
    fakeAcknowledge(acknowledgeResponse([
        'ErrorCode' => '2',
        'ErrorMessage' => 'Order declined due to payment credentials error',
        'OrderStatus' => 6,
        'params' => ['respCode' => '05', 'respCode_desc' => 'Votre transaction a été refusée'],
    ]));

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->rejected())->toBeTrue()
        ->and($result->message)->toBe('Votre transaction a été refusée')
        ->and($result->errorCode)->toBe('2')
        ->and($result->errorMessage)->toBe('Order declined due to payment credentials error');
});

it('falls back to the action code description when the resp code description is empty', function (mixed $respCodeDesc) {
    fakeAcknowledge(acknowledgeResponse([
        'actionCodeDescription' => 'Fallback description',
        'params' => ['respCode' => '05', 'respCode_desc' => $respCodeDesc],
    ]));

    expect(Satim::acknowledge(ORDER_ID)->message)->toBe('Fallback description');
})->with(['', null]);

it('has no message when SATIM sent no wording at all', function () {
    fakeAcknowledge(['ErrorCode' => '6', 'ErrorMessage' => 'Unregistered order ID']);

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->message)->toBeNull()
        ->and($result->rejected())->toBeTrue()
        ->and($result->respCode)->toBeNull();
});

it('exposes the amount in centimes and in dinars', function () {
    fakeAcknowledge(acknowledgeResponse());

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->amountInCentimes)->toBe(100320)
        ->and($result->amount)->toBe(1003.20);
});

it('leaves both amounts null when SATIM sent none', function () {
    fakeAcknowledge(acknowledgeResponse(['Amount' => null]));

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->amountInCentimes)->toBeNull()
        ->and($result->amount)->toBeNull();
});

it('exposes the payment and card details', function () {
    fakeAcknowledge(acknowledgeResponse());

    $result = Satim::acknowledge(ORDER_ID);

    expect($result)
        ->orderNumber->toBe('CMD0000004')
        ->maskedCard->toBe('6280****7215')
        ->cardholderName->toBe('cardholder Name')
        ->expiration->toBe('202701')
        ->approvalCode->toBe('913180')
        ->currency->toBe('012')
        ->ip->toBe('10.12.12.14')
        ->respCode->toBe('00')
        ->actionCode->toBe('0');
});

it('falls back to the authorization response id when the approval code is absent', function () {
    fakeAcknowledge(acknowledgeResponse(['approvalCode' => null, 'authorizationResponseId' => '777123']));

    expect(Satim::acknowledge(ORDER_ID)->approvalCode)->toBe('777123');
});

it('exposes the raw payloads for persistence with the credentials redacted', function () {
    fakeAcknowledge(acknowledgeResponse());

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->rawRequest)
        ->userName->toBe('[REDACTED]')
        ->password->toBe('[REDACTED]')
        ->mdOrder->toBe(ORDER_ID)
        ->and($result->rawResponse)->toHaveKey('SvfeResponse', '00');
});

it('throws when the gateway is unreachable', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => Satim::acknowledge(ORDER_ID))
        ->toThrow(SatimConnectionException::class, 'Operation timed out');
});

it('throws on a server error status', function () {
    Http::fake([ACKNOWLEDGE_URL => Http::response('Service Unavailable', 503)]);

    expect(fn () => Satim::acknowledge(ORDER_ID))
        ->toThrow(SatimConnectionException::class, 'responded with HTTP status 503');
});
