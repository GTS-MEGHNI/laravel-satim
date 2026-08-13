<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Testing;

use GtsMeghni\Satim\Enums\SatimOperation;

/**
 * One gateway call a test made while the fake was installed.
 */
final readonly class SatimRecordedCall
{
    /**
     * @param  array<string, string>  $payload  What was sent, with credentials redacted.
     * @param  array<string, mixed>  $response  What the fake answered, empty when it raised a connection failure.
     */
    public function __construct(
        public SatimOperation $operation,
        public string $endpoint,
        public array $payload,
        public array $response = [],
    ) {}

    /**
     * The order number, on the register call that carries one.
     */
    public function orderNumber(): ?string
    {
        $value = $this->payload['orderNumber'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The order id, under whichever name the endpoint gives it: acknowledge
     * sends mdOrder, refund sends orderId, and register has none yet.
     */
    public function orderId(): ?string
    {
        $value = $this->payload['mdOrder'] ?? $this->payload['orderId'] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * The amount in centimes, as SATIM was asked for it.
     */
    public function amountInCentimes(): ?int
    {
        $value = $this->payload['amount'] ?? null;

        return is_string($value) && is_numeric($value) ? (int) $value : null;
    }
}
