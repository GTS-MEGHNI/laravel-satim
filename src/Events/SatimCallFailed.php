<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Events;

use GtsMeghni\Satim\Enums\SatimOperation;

/**
 * The gateway could not be reached, or answered unusably.
 *
 * This is the case an audit trail most needs and most easily loses: money may
 * have moved even though your application never saw a reply.
 */
final readonly class SatimCallFailed
{
    public function __construct(
        public string $callId,
        public SatimOperation $operation,
        public string $reason,
    ) {}
}
