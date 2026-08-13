<?php

declare(strict_types=1);

use GtsMeghni\Satim\Enums\SatimErrorCode;
use GtsMeghni\Satim\Enums\SatimOperation;
use GtsMeghni\Satim\Facades\Satim;
use GtsMeghni\Satim\Testing\SatimFakeResponse;

it('names the code SATIM returned on a rejected registration', function () {
    Satim::fake(['register' => SatimFakeResponse::registerFailed(errorCode: '1')]);

    $result = Satim::register('K9m2X7qL4P', 5000);

    expect($result->error())->toBe(SatimErrorCode::OrderAlreadyProcessed)
        ->and($result->error()?->successful())->toBeFalse();
});

it('names the code on a refund the gateway turned down', function () {
    Satim::fake(['refund' => SatimFakeResponse::refundFailed(errorCode: '7')]);

    expect(Satim::refund(ORDER_ID, 5000)->error())->toBe(SatimErrorCode::SystemError);
});

it('names the code on an acknowledged payment', function () {
    Satim::fake();

    $result = Satim::acknowledge(ORDER_ID);

    expect($result->error())->toBe(SatimErrorCode::None)
        ->and($result->error()?->successful())->toBeTrue();
});

it('leaves a code it does not know unnamed, keeping the raw value', function () {
    Satim::fake(['register' => SatimFakeResponse::registerFailed(errorCode: '99')]);

    $result = Satim::register('K9m2X7qL4P', 5000);

    expect($result->error())->toBeNull()
        ->and($result->errorCode)->toBe('99');
});

it('has no code at all when SATIM sent none', function () {
    Satim::fake(['refund' => SatimFakeResponse::make(['errorMessage' => 'Something happened'])]);

    expect(Satim::refund(ORDER_ID, 5000)->error())->toBeNull();
});

it('describes the same code differently per operation, as the guide does', function () {
    expect(SatimErrorCode::InvalidParameter->description(SatimOperation::Register))
        ->toContain('jsonParams')
        ->and(SatimErrorCode::InvalidParameter->description(SatimOperation::Acknowledge))
        ->toContain('order id was empty')
        ->and(SatimErrorCode::InvalidParameter->description(SatimOperation::Refund))
        ->toContain('refund amount')
        ->and(SatimErrorCode::SystemError->description(SatimOperation::Refund))
        ->toContain('not in a state that allows a refund')
        ->and(SatimErrorCode::SystemError->description(SatimOperation::Register))
        ->toBe('System error.');
});

it('describes every code on every operation', function (SatimErrorCode $code, SatimOperation $operation) {
    expect($code->description($operation))->not->toBeEmpty();
})->with(SatimErrorCode::cases())->with(SatimOperation::cases());

it('treats only a zero code as no error', function () {
    expect(SatimErrorCode::None->successful())->toBeTrue()
        ->and(SatimErrorCode::Declined->successful())->toBeFalse();
});
