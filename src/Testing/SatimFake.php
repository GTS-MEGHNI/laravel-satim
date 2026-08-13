<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Testing;

use Closure;
use GtsMeghni\Satim\Contracts\SatimTransport;
use GtsMeghni\Satim\Enums\SatimOperation;
use GtsMeghni\Satim\Exceptions\SatimConnectionException;
use PHPUnit\Framework\Assert;

/**
 * Stands in for the gateway during a test.
 *
 * Installed by Satim::fake(), it replaces the transport and nothing else: the
 * amount conversion, the order number and udf validation, the events, and the
 * audit trail all still run, so a test cannot pass on a call that production
 * would refuse to send.
 *
 * Responses are queued per operation. A queue with several entries is consumed
 * one call at a time and its last entry then answers every further call, which
 * is what a reconciliation loop calling acknowledge twice needs.
 */
final class SatimFake implements SatimTransport
{
    /**
     * Queued answers, keyed by operation value.
     *
     * @var array<string, list<SatimFakeResponse>>
     */
    private array $responses = [];

    /**
     * Every call made since the fake was installed, in order.
     *
     * @var list<SatimRecordedCall>
     */
    private array $recorded = [];

    /**
     * @param  array<string, SatimFakeResponse|iterable<SatimFakeResponse>>  $responses
     *                                                                                   Keyed by operation value: "register", "acknowledge", or "refund".
     *                                                                                   An operation left out answers with a success.
     */
    public function __construct(array $responses = [])
    {
        foreach ($responses as $operation => $response) {
            $this->responses[$operation] = $response instanceof SatimFakeResponse
                ? [$response]
                : iterator_to_array($response, false);
        }
    }

    /**
     * Answer the call as the gateway would have, and remember it.
     *
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     *
     * @throws SatimConnectionException When the queued answer stages a transport failure.
     */
    public function post(SatimOperation $operation, array $payload): array
    {
        $response = $this->next($operation);
        $redacted = array_merge($payload, ['userName' => '[REDACTED]', 'password' => '[REDACTED]']);

        $this->recorded[] = new SatimRecordedCall(
            operation: $operation,
            endpoint: $operation->endpoint(),
            payload: $redacted,
            response: $response->body,
        );

        if ($response->exception !== null) {
            throw $response->exception;
        }

        return $response->body;
    }

    /**
     * The calls made, optionally narrowed to one operation.
     *
     * @param  (Closure(SatimRecordedCall): bool)|null  $callback
     * @return list<SatimRecordedCall>
     */
    public function recorded(?SatimOperation $operation = null, ?Closure $callback = null): array
    {
        $calls = array_filter(
            $this->recorded,
            fn (SatimRecordedCall $call): bool => ($operation === null || $call->operation === $operation)
                && ($callback === null || $callback($call)),
        );

        return array_values($calls);
    }

    /**
     * An order was registered, optionally one the callback accepts.
     *
     * @param  (Closure(SatimRecordedCall): bool)|null  $callback
     */
    public function assertRegistered(?Closure $callback = null): self
    {
        return $this->assertSent(SatimOperation::Register, $callback);
    }

    /**
     * A payment outcome was confirmed, optionally for one order id.
     *
     * @param  (Closure(SatimRecordedCall): bool)|string|null  $orderId
     */
    public function assertAcknowledged(Closure|string|null $orderId = null): self
    {
        return $this->assertSent(SatimOperation::Acknowledge, $this->matcher($orderId));
    }

    /**
     * A refund was requested, optionally for one order id and amount.
     *
     * @param  (Closure(SatimRecordedCall): bool)|string|null  $orderId
     * @param  int|float|null  $amount  Amount in DZD, compared against the centimes that were sent.
     */
    public function assertRefunded(Closure|string|null $orderId = null, int|float|null $amount = null): self
    {
        $matcher = $this->matcher($orderId);
        $centimes = $amount === null ? null : (int) round($amount * 100);

        return $this->assertSent(
            SatimOperation::Refund,
            fn (SatimRecordedCall $call): bool => ($matcher === null || $matcher($call))
                && ($centimes === null || $call->amountInCentimes() === $centimes),
        );
    }

    /**
     * The operation was called at least once, optionally with a matching payload.
     *
     * @param  (Closure(SatimRecordedCall): bool)|null  $callback
     */
    public function assertSent(SatimOperation $operation, ?Closure $callback = null): self
    {
        Assert::assertNotEmpty(
            $this->recorded($operation, $callback),
            "The SATIM [{$operation->value}] call was not sent.",
        );

        return $this;
    }

    /**
     * The operation was never called, or never with a matching payload.
     *
     * @param  (Closure(SatimRecordedCall): bool)|null  $callback
     */
    public function assertNotSent(SatimOperation $operation, ?Closure $callback = null): self
    {
        Assert::assertEmpty(
            $this->recorded($operation, $callback),
            "The SATIM [{$operation->value}] call was sent, and was not expected to be.",
        );

        return $this;
    }

    /**
     * Nothing reached the gateway, which is what a rejected input must produce.
     */
    public function assertNothingSent(): self
    {
        Assert::assertSame([], $this->recorded, 'SATIM calls were sent, and none were expected.');

        return $this;
    }

    /**
     * Exactly this many calls were made, across every operation.
     */
    public function assertSentCount(int $count): self
    {
        Assert::assertCount($count, $this->recorded, "SATIM did not receive exactly $count calls.");

        return $this;
    }

    /**
     * Turn an order id into a matcher, so both forms reach the same assertion.
     *
     * @param  (Closure(SatimRecordedCall): bool)|string|null  $orderId
     * @return (Closure(SatimRecordedCall): bool)|null
     */
    private function matcher(Closure|string|null $orderId): ?Closure
    {
        if ($orderId === null || $orderId instanceof Closure) {
            return $orderId;
        }

        return fn (SatimRecordedCall $call): bool => $call->orderId() === $orderId;
    }

    /**
     * The answer for this call: queued entries are consumed in order, and the
     * last one is kept so a repeated call still gets an answer.
     */
    private function next(SatimOperation $operation): SatimFakeResponse
    {
        $queue = $this->responses[$operation->value] ?? [];

        if ($queue === []) {
            return $this->default($operation);
        }

        if (count($queue) > 1) {
            $response = array_shift($queue);
            $this->responses[$operation->value] = $queue;

            return $response;
        }

        return $queue[0];
    }

    /**
     * What an operation answers when the test did not stage anything: a plain
     * success, so a test about something else does not have to describe one.
     */
    private function default(SatimOperation $operation): SatimFakeResponse
    {
        return match ($operation) {
            SatimOperation::Register => SatimFakeResponse::registered(),
            SatimOperation::Acknowledge => SatimFakeResponse::paid(),
            SatimOperation::Refund => SatimFakeResponse::refunded(),
        };
    }
}
