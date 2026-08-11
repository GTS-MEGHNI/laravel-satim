<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Events;

use GtsMeghni\Satim\Enums\SatimOperation;

/**
 * A gateway call is about to be sent.
 *
 * Fired before the request leaves, so a call that never comes back still leaves
 * a trace. Listen to this and the two events below to keep your own audit trail
 * instead of, or alongside, the one this package can record for you.
 */
final readonly class SatimCallStarted
{
    /**
     * @param  string  $callId  Correlates this event with its completion or failure.
     * @param  array<string, string>  $payload  What was sent, with credentials redacted.
     */
    public function __construct(
        public string $callId,
        public SatimOperation $operation,
        public string $endpoint,
        public array $payload,
        public ?string $orderNumber = null,
        public ?string $orderId = null,
        public ?int $amountInCentimes = null,
    ) {}
}
