<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Data;

use GtsMeghni\Satim\Data\Concerns\ReadsSatimResponse;

/**
 * Outcome of a call to the SATIM refund endpoint.
 *
 * Unlike register, success here is an errorCode of 0: there is no identifier to
 * look for. A refund SATIM turned down is a normal outcome rather than an
 * exception, so check successful() and keep both payloads.
 */
final readonly class RefundResult
{
    use ReadsSatimResponse;

    /**
     * The only code that means money was returned to the customer.
     */
    private const string SUCCESS_ERROR_CODE = '0';

    /**
     * @param  string  $orderId  Taken from the request: SATIM returns only a code.
     * @param  int  $amountInCentimes  Amount refunded, as sent to SATIM.
     * @param  float  $amount  The same amount in dinars.
     * @param  array<string, string>  $rawRequest  Payload sent to SATIM, with credentials redacted.
     * @param  array<string, mixed>  $rawResponse  Decoded SATIM response, verbatim.
     */
    public function __construct(
        public string $orderId,
        public int $amountInCentimes,
        public float $amount,
        public ?string $errorCode,
        public ?string $errorMessage,
        public array $rawRequest,
        public array $rawResponse,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, string>  $rawRequest
     */
    public static function fromResponse(
        string $orderId,
        int $amountInCentimes,
        array $response,
        array $rawRequest,
    ): self {
        return new self(
            orderId: $orderId,
            amountInCentimes: $amountInCentimes,
            amount: $amountInCentimes / 100,
            errorCode: self::stringValue($response, 'errorCode'),
            errorMessage: self::stringValue($response, 'errorMessage'),
            rawRequest: $rawRequest,
            rawResponse: $response,
        );
    }

    /**
     * The money was returned.
     *
     * A missing code counts as a failure: on something this consequential, an
     * answer the package cannot read is not treated as a success.
     */
    public function successful(): bool
    {
        return $this->errorCode === self::SUCCESS_ERROR_CODE;
    }

    public function failed(): bool
    {
        return ! $this->successful();
    }
}
