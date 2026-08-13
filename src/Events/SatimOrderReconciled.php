<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Events;

use GtsMeghni\Satim\Data\AcknowledgeResult;

/**
 * An order the customer never came back from was resolved with the gateway.
 *
 * The package knows SATIM's answer but nothing about your order, so this is
 * where you take over: mark the order paid, failed, or abandoned from the
 * result. Fired once per order, by the satim:reconcile command.
 */
final readonly class SatimOrderReconciled
{
    public function __construct(
        public string $orderId,
        public AcknowledgeResult $result,
    ) {}
}
