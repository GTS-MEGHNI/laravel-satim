<?php

declare(strict_types=1);

use GtsMeghni\Satim\Enums\SatimOperation;
use GtsMeghni\Satim\Enums\SatimOrderStatus;
use GtsMeghni\Satim\Events\SatimCallCompleted;
use GtsMeghni\Satim\Events\SatimCallStarted;
use GtsMeghni\Satim\Exceptions\SatimConnectionException;
use GtsMeghni\Satim\Facades\Satim;
use GtsMeghni\Satim\Models\SatimCall;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

function registerAnOrder(): void
{
    Http::fake([
        REGISTER_URL => Http::response([
            'errorCode' => 0,
            'orderId' => ORDER_ID,
            'formUrl' => 'https://test2.satim.dz/payment/form',
        ]),
    ]);

    Satim::register('K9m2X7qL4P', 5000);
}

it('records one row per call', function () {
    registerAnOrder();
    fakeAcknowledge(acknowledgeResponse());
    Satim::acknowledge(ORDER_ID);
    Http::fake([REFUND_URL => Http::response(['errorCode' => 0])]);
    Satim::refund(ORDER_ID, 200);

    expect(SatimCall::query()->count())->toBe(3)
        ->and(SatimCall::query()->orderBy('id')->pluck('operation')->all())->toBe([
            SatimOperation::Register,
            SatimOperation::Acknowledge,
            SatimOperation::Refund,
        ]);
});

it('stores what was sent and what came back', function () {
    registerAnOrder();

    $call = SatimCall::query()->sole();

    expect($call->operation)->toBe(SatimOperation::Register)
        ->and($call->order_number)->toBe('K9m2X7qL4P')
        ->and($call->order_id)->toBe(ORDER_ID)
        ->and($call->amount_in_centimes)->toBe(500000)
        ->and($call->request)->toHaveKey('orderNumber', 'K9m2X7qL4P')
        ->and($call->response)->toHaveKey('orderId', ORDER_ID)
        ->and($call->successful)->toBeTrue()
        ->and($call->completed_at)->not->toBeNull();
});

it('never stores the credentials', function () {
    registerAnOrder();

    $call = SatimCall::query()->sole();

    expect($call->request)
        ->userName->toBe('[REDACTED]')
        ->password->toBe('[REDACTED]')
        ->and(json_encode($call->request))->not->toContain('merchant-secret');
});

it('records a rejected registration as unsuccessful but complete', function () {
    Http::fake([REGISTER_URL => Http::response(['errorCode' => 1, 'errorMessage' => 'Already processed'])]);

    Satim::register('K9m2X7qL4P', 5000);

    $call = SatimCall::query()->sole();

    expect($call->successful)->toBeFalse()
        ->and($call->error_code)->toBe('1')
        ->and($call->error_message)->toBe('Already processed')
        ->and($call->completed_at)->not->toBeNull();
});

it('records a confirmed but rejected payment as unsuccessful', function () {
    fakeAcknowledge(acknowledgeResponse(['OrderStatus' => 6, 'params' => ['respCode' => '05']]));

    Satim::acknowledge(ORDER_ID);

    $call = SatimCall::query()->sole();

    expect($call->successful)->toBeFalse()
        ->and($call->order_status)->toBe(6)
        ->and($call->completed_at)->not->toBeNull();
});

it('keeps a record of a call that never came back', function () {
    Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

    expect(fn () => Satim::refund(ORDER_ID, 200))->toThrow(SatimConnectionException::class);

    $call = SatimCall::query()->sole();

    expect($call->operation)->toBe(SatimOperation::Refund)
        ->and($call->amount_in_centimes)->toBe(20000)
        ->and($call->response)->toBeNull()
        ->and($call->completed_at)->toBeNull()
        ->and($call->successful)->toBeFalse()
        ->and($call->failure_reason)->toContain('Operation timed out');
});

it('writes the row before the call is sent', function () {
    Http::fake(function () {
        // Mid-flight: the row must already exist.
        expect(SatimCall::query()->count())->toBe(1);

        return Http::response(['errorCode' => 0]);
    });

    Satim::refund(ORDER_ID, 200);
});

it('creates its table from the migration the package loads itself', function () {
    expect(Schema::hasTable('satim_calls'))->toBeTrue();
});

it('records without the setting being present at all', function () {
    config()->offsetUnset('satim.audit');

    registerAnOrder();

    expect(SatimCall::query()->count())->toBe(1);
});

it('ships with recording switched on', function () {
    $config = require __DIR__.'/../../config/satim.php';

    expect($config['audit']['enabled'])->toBeTrue();
});

it('records nothing when the audit trail is switched off', function () {
    config()->set('satim.audit.enabled', false);

    registerAnOrder();

    expect(SatimCall::query()->count())->toBe(0);
});

it('correlates the start and the completion of one call', function () {
    $ids = [];
    Event::listen(SatimCallStarted::class, function (SatimCallStarted $e) use (&$ids) {
        $ids['started'] = $e->callId;
    });
    Event::listen(SatimCallCompleted::class, function (SatimCallCompleted $e) use (&$ids) {
        $ids['completed'] = $e->callId;
    });

    registerAnOrder();

    expect($ids['started'])->toBe($ids['completed'])
        ->and(SatimCall::query()->sole()->call_id)->toBe($ids['started']);
});

it('tells the story of an order from its rows', function () {
    registerAnOrder();
    fakeAcknowledge(acknowledgeResponse());
    Satim::acknowledge(ORDER_ID);
    Http::fake([REFUND_URL => Http::response(['errorCode' => 0])]);
    Satim::refund(ORDER_ID, 300);
    Satim::refund(ORDER_ID, 200);

    $state = SatimCall::stateFor(ORDER_ID);

    expect($state->registered())->toBeTrue()
        ->and($state->acknowledged())->toBeTrue()
        ->and($state->paid())->toBeTrue()
        ->and($state->refunded())->toBeTrue()
        ->and($state->refundedInCentimes())->toBe(50000)
        ->and($state->orderStatus())->toBe(SatimOrderStatus::Deposited)
        ->and($state->calls)->toHaveCount(4)
        ->and($state->unanswered())->toBeEmpty();
});

it('reports an order that was registered but never confirmed', function () {
    registerAnOrder();

    $state = SatimCall::stateFor(ORDER_ID);

    expect($state->registered())->toBeTrue()
        ->and($state->acknowledged())->toBeFalse()
        ->and($state->paid())->toBeFalse()
        ->and($state->refunded())->toBeFalse()
        ->and($state->refundedInCentimes())->toBe(0)
        ->and($state->orderStatus())->toBeNull();
});

it('does not count a refused refund towards the total returned', function () {
    registerAnOrder();
    Http::fake([REFUND_URL => Http::response(['errorCode' => 5, 'errorMessage' => 'Invalid amount.'])]);

    Satim::refund(ORDER_ID, 200);

    $state = SatimCall::stateFor(ORDER_ID);

    expect($state->refunded())->toBeFalse()
        ->and($state->refundedInCentimes())->toBe(0);
});

it('lists calls that need chasing with SATIM', function () {
    registerAnOrder();
    Http::fake(fn () => throw new ConnectionException('timed out'));

    expect(fn () => Satim::refund(ORDER_ID, 200))->toThrow(SatimConnectionException::class);

    expect(SatimCall::stateFor(ORDER_ID)->unanswered())->toHaveCount(1);
});

it('never stamps an update time, because rows are not edited', function () {
    registerAnOrder();

    expect(SatimCall::query()->sole()->getAttributes())->not->toHaveKey('updated_at');
});

it('uses the table name from config', function () {
    expect((new SatimCall)->getTable())->toBe('satim_calls');
});
