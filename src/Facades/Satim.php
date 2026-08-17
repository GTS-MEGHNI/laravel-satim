<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Facades;

use Closure;
use GtsMeghni\Satim\Contracts\SatimTransport;
use GtsMeghni\Satim\Enums\SatimOperation;
use GtsMeghni\Satim\Testing\SatimFake;
use GtsMeghni\Satim\Testing\SatimFakeResponse;
use GtsMeghni\Satim\Testing\SatimRecordedCall;
use Illuminate\Container\Container;
use Illuminate\Support\Facades\Facade;
use RuntimeException;

/**
 * @method static string orderNumber(int $attempts = 5)
 * @method static \GtsMeghni\Satim\Data\RegisterResult register(string $orderNumber, int|float|string $amount, ?string $description = null, \GtsMeghni\Satim\Enums\SatimLanguage|string|null $language = null, ?string $udf1 = null, ?string $udf2 = null, ?string $udf3 = null, ?string $udf4 = null, ?string $udf5 = null, ?string $fundingTypeIndicator = null)
 * @method static \GtsMeghni\Satim\Data\AcknowledgeResult acknowledge(string $orderId, \GtsMeghni\Satim\Enums\SatimLanguage|string|null $language = null)
 * @method static \GtsMeghni\Satim\Data\RefundResult refund(string $orderId, int|float|string $amount, \GtsMeghni\Satim\Enums\SatimLanguage|string|null $language = null)
 * @method static \GtsMeghni\Satim\Data\Receipt receipt(\GtsMeghni\Satim\Data\AcknowledgeResult $result, ?\DateTimeInterface $paidAt = null, \GtsMeghni\Satim\Enums\SatimLanguage|string|null $language = null)
 * @method static string supportMessage(\GtsMeghni\Satim\Enums\SatimLanguage|string|null $language = null)
 *
 * @see \GtsMeghni\Satim\Satim
 */
class Satim extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \GtsMeghni\Satim\Satim::class;
    }

    /**
     * Replace the gateway with a fake for the rest of the test.
     *
     * Only the transport is replaced, so validation, events, and the audit
     * trail still run. Every operation answers with a success unless the test
     * stages something else:
     *
     *     Satim::fake([
     *         'acknowledge' => SatimFakeResponse::declined(),
     *     ]);
     *
     * @param  array<string, SatimFakeResponse|iterable<SatimFakeResponse>>  $responses
     *                                                                                   Keyed by operation value: "register", "acknowledge", or "refund".
     */
    public static function fake(array $responses = []): SatimFake
    {
        $fake = new SatimFake($responses);
        $container = Container::getInstance();

        $container->instance(SatimTransport::class, $fake);

        // The service is a singleton, so one resolved earlier in the test still
        // holds the real transport. Drop it and let the fake be injected.
        $container->forgetInstance(\GtsMeghni\Satim\Satim::class);
        static::clearResolvedInstance(static::getFacadeAccessor());

        return $fake;
    }

    /**
     * @param  (Closure(SatimRecordedCall): bool)|null  $callback
     *
     * @throws RuntimeException When the gateway was not faked.
     */
    public static function assertRegistered(?Closure $callback = null): void
    {
        self::fakeInstance()->assertRegistered($callback);
    }

    /**
     * @param  (Closure(SatimRecordedCall): bool)|string|null  $orderId
     *
     * @throws RuntimeException When the gateway was not faked.
     */
    public static function assertAcknowledged(Closure|string|null $orderId = null): void
    {
        self::fakeInstance()->assertAcknowledged($orderId);
    }

    /**
     * @param  (Closure(SatimRecordedCall): bool)|string|null  $orderId
     * @param  int|float|null  $amount  Amount in DZD.
     *
     * @throws RuntimeException When the gateway was not faked.
     */
    public static function assertRefunded(Closure|string|null $orderId = null, int|float|null $amount = null): void
    {
        self::fakeInstance()->assertRefunded($orderId, $amount);
    }

    /**
     * @param  (Closure(SatimRecordedCall): bool)|null  $callback
     *
     * @throws RuntimeException When the gateway was not faked.
     */
    public static function assertSent(SatimOperation $operation, ?Closure $callback = null): void
    {
        self::fakeInstance()->assertSent($operation, $callback);
    }

    /**
     * @param  (Closure(SatimRecordedCall): bool)|null  $callback
     *
     * @throws RuntimeException When the gateway was not faked.
     */
    public static function assertNotSent(SatimOperation $operation, ?Closure $callback = null): void
    {
        self::fakeInstance()->assertNotSent($operation, $callback);
    }

    /**
     * @throws RuntimeException When the gateway was not faked.
     */
    public static function assertNothingSent(): void
    {
        self::fakeInstance()->assertNothingSent();
    }

    /**
     * @throws RuntimeException When the gateway was not faked.
     */
    public static function assertSentCount(int $count): void
    {
        self::fakeInstance()->assertSentCount($count);
    }

    /**
     * The calls made against the fake, optionally narrowed to one operation.
     *
     * @param  (Closure(SatimRecordedCall): bool)|null  $callback
     * @return list<SatimRecordedCall>
     *
     * @throws RuntimeException When the gateway was not faked.
     */
    public static function recorded(?SatimOperation $operation = null, ?Closure $callback = null): array
    {
        return self::fakeInstance()->recorded($operation, $callback);
    }

    /**
     * The installed fake, or a clear complaint that there is none: asserting
     * against the real transport would otherwise pass silently.
     *
     * @throws RuntimeException
     */
    private static function fakeInstance(): SatimFake
    {
        $container = Container::getInstance();
        $transport = $container->bound(SatimTransport::class) ? $container->get(SatimTransport::class) : null;

        if (! $transport instanceof SatimFake) {
            throw new RuntimeException('The SATIM gateway was not faked. Call Satim::fake() before asserting.');
        }

        return $transport;
    }
}
