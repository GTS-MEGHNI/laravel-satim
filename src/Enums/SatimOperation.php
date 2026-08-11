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
}
