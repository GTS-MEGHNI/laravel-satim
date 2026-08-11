<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Data;

use GtsMeghni\Satim\Data\Concerns\ReadsSatimResponse;

/**
 * Outcome of a call to the SATIM register endpoint.
 *
 * A gateway-side rejection (duplicate order number, blocked merchant, ...) is a
 * normal outcome rather than an exception: check successful() and persist the
 * raw payloads, which SATIM may ask for during certification or an audit.
 */
final readonly class RegisterResult
{
    use ReadsSatimResponse;

    /**
     * @param  array<string, string>  $rawRequest  Payload sent to SATIM, with credentials redacted.
     * @param  array<string, mixed>  $rawResponse  Decoded SATIM response, verbatim.
     */
    public function __construct(
        public ?string $orderId,
        public ?string $formUrl,
        public ?string $errorCode,
        public ?string $errorMessage,
        public array $rawRequest,
        public array $rawResponse,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, string>  $rawRequest
     */
    public static function fromResponse(array $response, array $rawRequest): self
    {
        return new self(
            orderId: self::stringValue($response, 'orderId'),
            formUrl: self::stringValue($response, 'formUrl'),
            errorCode: self::stringValue($response, 'errorCode'),
            errorMessage: self::stringValue($response, 'errorMessage'),
            rawRequest: $rawRequest,
            rawResponse: $response,
        );
    }

    /**
     * SATIM registered the order: the documented success criterion is the
     * presence of an orderId.
     */
    public function successful(): bool
    {
        return $this->orderId !== null;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }
}
