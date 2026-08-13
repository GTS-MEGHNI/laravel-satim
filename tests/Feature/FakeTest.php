<?php

declare(strict_types=1);

use GtsMeghni\Satim\Enums\SatimOperation;
use GtsMeghni\Satim\Exceptions\SatimConnectionException;
use GtsMeghni\Satim\Exceptions\SatimValidationException;
use GtsMeghni\Satim\Facades\Satim;
use GtsMeghni\Satim\Models\SatimCall;
use GtsMeghni\Satim\Testing\SatimFakeResponse;
use GtsMeghni\Satim\Testing\SatimRecordedCall;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\ExpectationFailedException;

it('answers every operation with a success when nothing is staged', function () {
    Satim::fake();

    expect(Satim::register('K9m2X7qL4P', 5000)->successful())->toBeTrue()
        ->and(Satim::acknowledge(ORDER_ID)->paid())->toBeTrue()
        ->and(Satim::refund(ORDER_ID, 5000)->successful())->toBeTrue();
});

it('never reaches the network', function () {
    Http::fake();
    Satim::fake();

    Satim::register('K9m2X7qL4P', 5000);

    Http::assertNothingSent();
});

it('stages a rejected registration', function () {
    Satim::fake(['register' => SatimFakeResponse::registerFailed(errorMessage: 'Order already processed')]);

    $result = Satim::register('K9m2X7qL4P', 5000);

    expect($result->failed())->toBeTrue()
        ->and($result->orderId)->toBeNull()
        ->and($result->errorMessage)->toBe('Order already processed');
});

it('stages a declined payment', function () {
    Satim::fake(['acknowledge' => SatimFakeResponse::declined(message: 'Votre transaction a été rejetée')]);

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->paid())->toBeFalse()
        ->and($result->rejected())->toBeTrue()
        ->and($result->message)->toBe('Votre transaction a été rejetée');
});

it('stages a reversed authorisation, which the package words itself', function () {
    Satim::fake(['acknowledge' => SatimFakeResponse::reversed()]);

    $result = Satim::acknowledge(ORDER_ID, language: 'EN');

    expect($result->paid())->toBeFalse()
        ->and($result->cancelled())->toBeTrue()
        ->and($result->message)->toBe('Your transaction was rejected');
});

it('stages a refund the gateway turned down', function () {
    Satim::fake(['refund' => SatimFakeResponse::refundFailed()]);

    $result = Satim::refund(ORDER_ID, 5000);

    expect($result->failed())->toBeTrue()
        ->and($result->errorCode)->toBe('7');
});

it('takes the paid amount from the staged response', function () {
    Satim::fake(['acknowledge' => SatimFakeResponse::paid(amount: 5966.56, orderNumber: 'K9m2X7qL4P')]);

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->amountInCentimes)->toBe(596656)
        ->and($result->amount)->toBe(5966.56)
        ->and($result->orderNumber)->toBe('K9m2X7qL4P');
});

it('accepts a whole response body written out by hand', function () {
    Satim::fake(['register' => SatimFakeResponse::make(['errorCode' => '5', 'errorMessage' => 'Access denied'])]);

    expect(Satim::register('K9m2X7qL4P', 5000)->errorMessage)->toBe('Access denied');
});

it('consumes a queue of responses in order and keeps answering with the last', function () {
    Satim::fake([
        'acknowledge' => [
            SatimFakeResponse::declined(),
            SatimFakeResponse::paid(),
        ],
    ]);

    expect(Satim::acknowledge(ORDER_ID)->paid())->toBeFalse()
        ->and(Satim::acknowledge(ORDER_ID)->paid())->toBeTrue()
        ->and(Satim::acknowledge(ORDER_ID)->paid())->toBeTrue();
});

it('stages a gateway that cannot be reached', function () {
    Satim::fake(['register' => SatimFakeResponse::connectionFailure()]);

    expect(fn () => Satim::register('K9m2X7qL4P', 5000))
        ->toThrow(SatimConnectionException::class, 'Operation timed out');
});

it('still rejects what the real gateway would never be sent', function () {
    Satim::fake();

    expect(fn () => Satim::register('too-short', 5000))
        ->toThrow(SatimValidationException::class, 'exactly 10 alphanumeric characters');

    Satim::assertNothingSent();
});

it('still records the audit trail', function () {
    Satim::fake();

    Satim::register('K9m2X7qL4P', 5000);

    $call = SatimCall::query()->sole();

    expect($call->operation)->toBe(SatimOperation::Register)
        ->and($call->successful)->toBeTrue()
        ->and($call->order_id)->toBe('V721uPPfNNofVQAAABL3');
});

it('asserts a registration by its payload', function () {
    Satim::fake();

    Satim::register('K9m2X7qL4P', 5966.56, udf2: 'invoice-7788');

    Satim::assertRegistered(fn (SatimRecordedCall $call): bool => $call->orderNumber() === 'K9m2X7qL4P'
        && $call->amountInCentimes() === 596656);
});

it('keeps the credentials out of the recorded payload', function () {
    Satim::fake();

    Satim::register('K9m2X7qL4P', 5000);

    expect(Satim::recorded(SatimOperation::Register)[0]->payload)
        ->userName->toBe('[REDACTED]')
        ->password->toBe('[REDACTED]');
});

it('asserts an acknowledgement by order id', function () {
    Satim::fake();

    Satim::acknowledge(ORDER_ID);

    Satim::assertAcknowledged(ORDER_ID);
});

it('asserts a refund by order id and amount in dinars', function () {
    Satim::fake();

    Satim::refund(ORDER_ID, 806.5);

    Satim::assertRefunded(ORDER_ID, amount: 806.5);
});

it('fails an assertion when the call was never made', function () {
    Satim::fake();

    Satim::acknowledge(ORDER_ID);

    expect(fn () => Satim::assertRefunded())
        ->toThrow(ExpectationFailedException::class, 'The SATIM [refund] call was not sent.');
});

it('fails an assertion when the order id does not match', function () {
    Satim::fake();

    Satim::acknowledge(ORDER_ID);

    expect(fn () => Satim::assertAcknowledged('another-order'))
        ->toThrow(ExpectationFailedException::class, 'was not sent');
});

it('asserts a call was not made', function () {
    Satim::fake();

    Satim::register('K9m2X7qL4P', 5000);

    Satim::assertNotSent(SatimOperation::Refund);

    expect(fn () => Satim::assertNotSent(SatimOperation::Register))
        ->toThrow(ExpectationFailedException::class, 'was sent, and was not expected to be');
});

it('counts the calls made across every operation', function () {
    Satim::fake();

    Satim::register('K9m2X7qL4P', 5000);
    Satim::acknowledge(ORDER_ID);

    Satim::assertSentCount(2);

    expect(fn () => Satim::assertSentCount(3))
        ->toThrow(ExpectationFailedException::class, 'SATIM did not receive exactly 3 calls.');
});

it('records the call that ended in a connection failure', function () {
    Satim::fake(['refund' => SatimFakeResponse::connectionFailure()]);

    try {
        Satim::refund(ORDER_ID, 5000);
    } catch (SatimConnectionException) {
        // The point of the test is what the fake kept, not the exception.
    }

    Satim::assertRefunded(ORDER_ID);
});

it('exposes the calls it recorded, narrowed to one operation', function () {
    Satim::fake();

    Satim::register('K9m2X7qL4P', 5000);
    Satim::acknowledge(ORDER_ID);

    expect(Satim::recorded())->toHaveCount(2)
        ->and(Satim::recorded(SatimOperation::Acknowledge))->toHaveCount(1)
        ->and(Satim::recorded(SatimOperation::Acknowledge)[0]->orderId())->toBe(ORDER_ID)
        ->and(Satim::recorded(SatimOperation::Acknowledge)[0]->endpoint)
        ->toBe('public/acknowledgeTransaction.do');
});

it('refuses to assert against a gateway that was never faked', function () {
    expect(fn () => Satim::assertNothingSent())
        ->toThrow(RuntimeException::class, 'The SATIM gateway was not faked.');
});

it('replaces a service that was already resolved', function () {
    Http::fake();

    // Resolving the service first is what an application does before a test
    // reaches for the fake, and the singleton would otherwise keep the real
    // transport it was built with.
    app(GtsMeghni\Satim\Satim::class);

    Satim::fake();
    Satim::register('K9m2X7qL4P', 5000);

    Http::assertNothingSent();
    Satim::assertRegistered();
});
