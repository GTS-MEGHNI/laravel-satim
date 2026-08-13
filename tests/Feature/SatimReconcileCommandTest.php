<?php

declare(strict_types=1);

use GtsMeghni\Satim\Enums\SatimOperation;
use GtsMeghni\Satim\Events\SatimOrderReconciled;
use GtsMeghni\Satim\Facades\Satim;
use GtsMeghni\Satim\Models\SatimCall;
use GtsMeghni\Satim\Testing\SatimFakeResponse;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;

/**
 * An order SATIM registered, with nothing recorded for it since.
 */
function unconfirmedOrder(string $orderId = ORDER_ID, int $minutesAgo = 60): SatimCall
{
    return SatimCall::query()->create([
        'call_id' => "call-$orderId",
        'operation' => SatimOperation::Register,
        'order_number' => 'K9m2X7qL4P',
        'order_id' => $orderId,
        'amount_in_centimes' => 500000,
        'request' => ['orderNumber' => 'K9m2X7qL4P'],
        'response' => ['orderId' => $orderId],
        'successful' => true,
        'created_at' => Date::now()->subMinutes($minutesAgo),
        'completed_at' => Date::now()->subMinutes($minutesAgo),
    ]);
}

/**
 * An answer already on record for that order.
 */
function answeredOrder(string $orderId = ORDER_ID): SatimCall
{
    return SatimCall::query()->create([
        'call_id' => "answer-$orderId",
        'operation' => SatimOperation::Acknowledge,
        'order_id' => $orderId,
        'request' => ['mdOrder' => $orderId],
        'response' => ['OrderStatus' => 2],
        'successful' => true,
        'order_status' => 2,
        'created_at' => Date::now()->subMinutes(30),
        'completed_at' => Date::now()->subMinutes(30),
    ]);
}

it('confirms an order the customer never returned from', function () {
    Satim::fake(['acknowledge' => SatimFakeResponse::paid()]);
    unconfirmedOrder();

    $this->artisan('satim:reconcile')
        ->expectsOutputToContain('paid')
        ->assertSuccessful();

    Satim::assertAcknowledged(ORDER_ID);
});

it('announces the outcome so the application can settle its own order', function () {
    Event::fake([SatimOrderReconciled::class]);
    Satim::fake(['acknowledge' => SatimFakeResponse::paid(amount: 5966.56)]);
    unconfirmedOrder();

    $this->artisan('satim:reconcile')->assertSuccessful();

    Event::assertDispatched(
        SatimOrderReconciled::class,
        fn (SatimOrderReconciled $event): bool => $event->orderId === ORDER_ID
            && $event->result->paid()
            && $event->result->amount === 5966.56,
    );
});

it('reports an order that was registered and never paid', function () {
    Satim::fake(['acknowledge' => SatimFakeResponse::declined()]);
    unconfirmedOrder();

    $this->artisan('satim:reconcile')
        ->expectsOutputToContain('AuthorisationDeclined')
        ->assertSuccessful();
});

it('leaves an order alone until it has gone unconfirmed long enough', function () {
    Satim::fake();
    unconfirmedOrder(minutesAgo: 5);

    $this->artisan('satim:reconcile')
        ->expectsOutputToContain('No unconfirmed orders.')
        ->assertSuccessful();

    Satim::assertNothingSent();
});

it('accepts a shorter wait on the command line', function () {
    Satim::fake();
    unconfirmedOrder(minutesAgo: 5);

    $this->artisan('satim:reconcile', ['--after' => 1])->assertSuccessful();

    Satim::assertAcknowledged(ORDER_ID);
});

it('ignores an order too old for the answer to matter', function () {
    Satim::fake();
    unconfirmedOrder(minutesAgo: 60 * 24 * 30);

    $this->artisan('satim:reconcile')->assertSuccessful();

    Satim::assertNothingSent();
});

it('skips an order that already has an answer on record', function () {
    Satim::fake();
    unconfirmedOrder();
    answeredOrder();

    $this->artisan('satim:reconcile')
        ->expectsOutputToContain('No unconfirmed orders.')
        ->assertSuccessful();

    Satim::assertNothingSent();
});

it('retries an order whose confirmation never came back', function () {
    Satim::fake();
    unconfirmedOrder();

    // A call that was recorded but never completed: the gateway was asked and
    // said nothing, which is the case most worth asking about again.
    SatimCall::query()->create([
        'call_id' => 'unanswered',
        'operation' => SatimOperation::Acknowledge,
        'order_id' => ORDER_ID,
        'request' => ['mdOrder' => ORDER_ID],
        'failure_reason' => 'Operation timed out',
        'created_at' => Date::now()->subMinutes(40),
    ]);

    $this->artisan('satim:reconcile')->assertSuccessful();

    Satim::assertAcknowledged(ORDER_ID);
});

it('skips a registration the gateway turned down', function () {
    Satim::fake();
    unconfirmedOrder()->update(['successful' => false, 'order_id' => null]);

    $this->artisan('satim:reconcile')->assertSuccessful();

    Satim::assertNothingSent();
});

it('confirms no more than the configured number of orders in one run', function () {
    Satim::fake();
    unconfirmedOrder('order-one');
    unconfirmedOrder('order-two');
    unconfirmedOrder('order-three');

    $this->artisan('satim:reconcile', ['--limit' => 2])->assertSuccessful();

    Satim::assertSentCount(2);
});

it('lists the orders without calling the gateway on a dry run', function () {
    Satim::fake();
    unconfirmedOrder();

    $this->artisan('satim:reconcile', ['--dry-run' => true])
        ->expectsOutputToContain('1 order would be confirmed.')
        ->assertSuccessful();

    Satim::assertNothingSent();
});

it('leaves an unreachable gateway for the next run instead of failing', function () {
    Satim::fake(['acknowledge' => SatimFakeResponse::connectionFailure()]);
    unconfirmedOrder();

    $this->artisan('satim:reconcile')
        ->expectsOutputToContain('0 of 1 order confirmed.')
        ->assertSuccessful();
});

it('refuses to run without the audit trail it reads from', function () {
    config()->set('satim.audit.enabled', false);
    Satim::fake();

    $this->artisan('satim:reconcile')
        ->expectsOutputToContain('audit trail is switched off')
        ->assertFailed();

    Satim::assertNothingSent();
});

it('is registered with artisan', function () {
    expect(app(Kernel::class)->all())->toHaveKey('satim:reconcile');
});
