<?php

declare(strict_types=1);

use GtsMeghni\Satim\Enums\SatimOrderStatus;
use GtsMeghni\Satim\Exceptions\SatimValidationException;
use GtsMeghni\Satim\Facades\Satim;

it('names the order status SATIM reported', function (int $sent, SatimOrderStatus $expected) {
    fakeAcknowledge(acknowledgeResponse(['OrderStatus' => $sent]));

    expect(Satim::acknowledge(ORDER_ID)->status)->toBe($expected);
})->with([
    [-1, SatimOrderStatus::Declined],
    [0, SatimOrderStatus::Registered],
    [1, SatimOrderStatus::Approved],
    [2, SatimOrderStatus::Deposited],
    [3, SatimOrderStatus::Reversed],
    [4, SatimOrderStatus::Refunded],
    [6, SatimOrderStatus::AuthorisationDeclined],
]);

it('leaves the named status empty when SATIM sent none', function () {
    fakeAcknowledge(acknowledgeResponse(['OrderStatus' => null]));

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->status)->toBeNull()
        ->and($result->orderStatus)->toBeNull()
        ->and($result->refunded())->toBeFalse()
        ->and($result->cancelled())->toBeFalse();
});

it('keeps the raw number when SATIM sends a status it does not know', function () {
    fakeAcknowledge(acknowledgeResponse(['OrderStatus' => 11]));

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->status)->toBeNull()
        ->and($result->orderStatus)->toBe(11);
});

it('notices a refund made in the SATIM back office', function () {
    fakeAcknowledge(acknowledgeResponse(['OrderStatus' => 4]));

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->refunded())->toBeTrue()
        ->and($result->cancelled())->toBeFalse()
        ->and($result->paid())->toBeFalse()
        ->and($result->status?->isReturned())->toBeTrue();
});

it('notices a cancellation made in the SATIM back office', function () {
    fakeAcknowledge(acknowledgeResponse(['OrderStatus' => 3]));

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->cancelled())->toBeTrue()
        ->and($result->refunded())->toBeFalse()
        ->and($result->paid())->toBeFalse()
        ->and($result->status?->isReturned())->toBeTrue();
});

it('does not treat a paid order as refunded or cancelled', function () {
    fakeAcknowledge(acknowledgeResponse());

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->paid())->toBeTrue()
        ->and($result->status?->isPaid())->toBeTrue()
        ->and($result->status?->isReturned())->toBeFalse()
        ->and($result->refunded())->toBeFalse()
        ->and($result->cancelled())->toBeFalse();
});

it('refuses a receipt for a refunded order', function () {
    fakeAcknowledge(acknowledgeResponse(['OrderStatus' => 4]));
    $refunded = Satim::acknowledge(ORDER_ID);

    expect(fn () => Satim::receipt($refunded))
        ->toThrow(SatimValidationException::class);
});
