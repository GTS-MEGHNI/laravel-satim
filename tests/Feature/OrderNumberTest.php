<?php

declare(strict_types=1);

use GtsMeghni\Satim\Enums\SatimOperation;
use GtsMeghni\Satim\Exceptions\SatimValidationException;
use GtsMeghni\Satim\Facades\Satim;
use GtsMeghni\Satim\Models\SatimCall;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;

/**
 * An order number this application already sent to SATIM.
 */
function usedOrderNumber(string $orderNumber): SatimCall
{
    return SatimCall::query()->create([
        'call_id' => "used-$orderNumber",
        'operation' => SatimOperation::Register,
        'order_number' => $orderNumber,
        'order_id' => "order-$orderNumber",
        'request' => [],
        'successful' => true,
        'created_at' => Date::now(),
        'completed_at' => Date::now(),
    ]);
}

afterEach(function () {
    Str::createRandomStringsNormally();
});

it('generates an order number in the format the gateway requires', function () {
    expect(Satim::orderNumber())->toMatch('/^[A-Za-z0-9]{10}$/');
});

it('generates a different number each time', function () {
    $numbers = collect(range(1, 50))->map(fn (): string => Satim::orderNumber());

    expect($numbers->unique())->toHaveCount(50);
});

it('is accepted by register without further validation', function () {
    Satim::fake();

    $orderNumber = Satim::orderNumber();
    Satim::register($orderNumber, 5000);

    Satim::assertRegistered(fn ($call): bool => $call->orderNumber() === $orderNumber);
});

it('skips a number this application already sent', function () {
    Str::createRandomStringsUsingSequence(['AAAAAAAAAA', 'BBBBBBBBBB']);
    usedOrderNumber('AAAAAAAAAA');

    expect(Satim::orderNumber())->toBe('BBBBBBBBBB');
});

it('has nothing to check against when the audit trail is off', function () {
    config()->set('satim.audit.enabled', false);
    Str::createRandomStringsUsingSequence(['AAAAAAAAAA', 'BBBBBBBBBB']);
    usedOrderNumber('AAAAAAAAAA');

    expect(Satim::orderNumber())->toBe('AAAAAAAAAA');
});

it('gives up rather than handing out a number that is already used', function () {
    Str::createRandomStringsUsing(fn (): string => 'AAAAAAAAAA');
    usedOrderNumber('AAAAAAAAAA');

    expect(fn () => Satim::orderNumber(attempts: 3))
        ->toThrow(SatimValidationException::class, 'in 3 attempts');
});
