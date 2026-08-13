<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Enums;

/**
 * The gateway calls this package makes.
 */
enum SatimOperation: string
{
    case Register = 'register';

    case Acknowledge = 'acknowledge';

    case Refund = 'refund';

    /**
     * Path appended to the configured base URL, without a leading slash.
     *
     * Kept here rather than at the call sites so the transport, the audit
     * trail, and the test fake all name an operation the same way.
     */
    public function endpoint(): string
    {
        return match ($this) {
            self::Register => 'register.do',
            self::Acknowledge => 'public/acknowledgeTransaction.do',
            self::Refund => 'refund.do',
        };
    }
}
