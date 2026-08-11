<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Events;

use GtsMeghni\Satim\Enums\SatimOperation;

/**
 * The gateway answered.
 *
 * "Successful" is the meaningful outcome of each call rather than merely a reply
 * arriving: an order id for register, a paid transaction for acknowledge, and a
 * zero error code for refund. A confirmed but rejected payment is therefore
 * completed and not successful.
 */
final readonly class SatimCallCompleted
{
    /**
     * @param  array<string, mixed>  $response  The decoded reply, verbatim.
     */
    public function __construct(
        public string $callId,
        public SatimOperation $operation,
        public array $response,
        public bool $successful,
        public ?string $orderId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public ?int $orderStatus = null,
    ) {}
}
