<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Data;

use GtsMeghni\Satim\Data\Concerns\ReadsSatimResponse;
use GtsMeghni\Satim\Enums\SatimErrorCode;
use GtsMeghni\Satim\Enums\SatimOrderStatus;

/**
 * Outcome of a call to the SATIM acknowledge endpoint.
 *
 * Whether the customer actually paid is decided by three fields read together,
 * never by one alone: respCode, errorCode and orderStatus.
 */
final readonly class AcknowledgeResult
{
    use ReadsSatimResponse;

    /**
     * The only respCode, errorCode and orderStatus that mean the money moved.
     */
    private const string PAID_RESP_CODE = '00';

    private const string PAID_ERROR_CODE = '0';

    private const int PAID_ORDER_STATUS = 2;

    /**
     * Authorisation reversed: SATIM reports no error, yet the payment failed.
     */
    private const int REVERSED_ORDER_STATUS = 3;

    /**
     * @param  string  $orderId  Taken from the request: SATIM does not echo it back.
     * @param  SatimOrderStatus|null  $status  The named order status, null when SATIM sent one this package does not know.
     * @param  string|null  $message  Outcome text to persist, per the SATIM integration guide.
     * @param  string|null  $respCodeDesc  SATIM's own wording, untouched.
     * @param  int|null  $amountInCentimes  Amount as SATIM sent it.
     * @param  float|null  $amount  The same amount in dinars.
     * @param  array<string, string>  $rawRequest  Payload sent to SATIM, with credentials redacted.
     * @param  array<string, mixed>  $rawResponse  Decoded SATIM response, verbatim.
     */
    public function __construct(
        public string $orderId,
        public ?string $message,
        public ?string $respCode,
        public ?string $respCodeDesc,
        public ?string $actionCode,
        public ?string $actionCodeDescription,
        public ?string $errorCode,
        public ?string $errorMessage,
        public ?int $orderStatus,
        public ?SatimOrderStatus $status,
        public ?string $orderNumber,
        public ?int $amountInCentimes,
        public ?float $amount,
        public ?string $maskedCard,
        public ?string $cardholderName,
        public ?string $expiration,
        public ?string $approvalCode,
        public ?string $currency,
        public ?string $ip,
        public array $rawRequest,
        public array $rawResponse,
    ) {}

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, string>  $rawRequest
     * @param  string  $reversalMessage  Localised wording required when the authorisation was reversed.
     */
    public static function fromResponse(
        string $orderId,
        array $response,
        array $rawRequest,
        string $reversalMessage,
    ): self {
        $params = self::params($response);
        $amountInCentimes = self::intValue($response, 'Amount');
        $orderStatus = self::intValue($response, 'OrderStatus');

        return new self(
            orderId: $orderId,
            message: self::message($response, $params, $reversalMessage),
            respCode: self::stringValue($params, 'respCode'),
            respCodeDesc: self::stringValue($params, 'respCode_desc'),
            actionCode: self::stringValue($response, 'actionCode'),
            actionCodeDescription: self::stringValue($response, 'actionCodeDescription'),
            errorCode: self::stringValue($response, 'ErrorCode'),
            errorMessage: self::stringValue($response, 'ErrorMessage'),
            orderStatus: $orderStatus,
            status: $orderStatus === null ? null : SatimOrderStatus::tryFrom($orderStatus),
            orderNumber: self::stringValue($response, 'OrderNumber'),
            amountInCentimes: $amountInCentimes,
            amount: $amountInCentimes === null ? null : $amountInCentimes / 100,
            maskedCard: self::stringValue($response, 'Pan'),
            cardholderName: self::stringValue($response, 'cardholderName'),
            expiration: self::stringValue($response, 'expiration'),
            approvalCode: self::stringValue($response, 'approvalCode')
                ?? self::stringValue($response, 'authorizationResponseId'),
            currency: self::stringValue($response, 'currency'),
            ip: self::stringValue($response, 'Ip'),
            rawRequest: $rawRequest,
            rawResponse: $response,
        );
    }

    /**
     * The named error code, or null when SATIM sent one this package does not
     * know. The raw value stays on $errorCode either way.
     */
    public function error(): ?SatimErrorCode
    {
        return $this->errorCode === null ? null : SatimErrorCode::tryFrom($this->errorCode);
    }

    /**
     * The customer paid and the amount was deposited.
     */
    public function paid(): bool
    {
        return $this->respCode === self::PAID_RESP_CODE
            && $this->errorCode === self::PAID_ERROR_CODE
            && $this->orderStatus === self::PAID_ORDER_STATUS;
    }

    public function rejected(): bool
    {
        return ! $this->paid();
    }

    /**
     * Refunded by staff in SATIM's back office.
     */
    public function refunded(): bool
    {
        return $this->status?->isRefunded() ?? false;
    }

    /**
     * Cancelled by staff in SATIM's back office.
     */
    public function cancelled(): bool
    {
        return $this->status?->isCancelled() ?? false;
    }

    /**
     * Outcome text, following the mapping in the SATIM integration guide.
     *
     * SATIM's own description is used throughout, except for a reversed
     * authorisation, where the guide mandates fixed localised wording because
     * the gateway itself reports no error.
     *
     * @param  array<string, mixed>  $response
     * @param  array<string, mixed>  $params
     */
    private static function message(array $response, array $params, string $reversalMessage): ?string
    {
        $reported = self::stringValue($params, 'respCode_desc')
            ?? self::stringValue($response, 'actionCodeDescription');

        $isReversed = self::stringValue($params, 'respCode') === self::PAID_RESP_CODE
            && self::stringValue($response, 'ErrorCode') === self::PAID_ERROR_CODE
            && self::intValue($response, 'OrderStatus') === self::REVERSED_ORDER_STATUS;

        return $isReversed ? $reversalMessage : $reported;
    }
}
