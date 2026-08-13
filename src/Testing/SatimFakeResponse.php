<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Testing;

use GtsMeghni\Satim\Enums\SatimOrderStatus;
use GtsMeghni\Satim\Exceptions\SatimConnectionException;

/**
 * A canned SATIM answer for use with Satim::fake().
 *
 * The named constructors exist so a test never has to know SATIM's field names,
 * its mixed value types, or which combination of respCode, ErrorCode and
 * OrderStatus means the money moved. Pass overrides for the fields a given test
 * is actually about, or build the whole body with make().
 */
final readonly class SatimFakeResponse
{
    /**
     * ISO 4217 numeric code for the Algerian dinar.
     */
    private const string DZD_CURRENCY = '012';

    /**
     * @param  array<string, mixed>  $body  The decoded body the fake returns.
     * @param  SatimConnectionException|null  $exception  Raised instead of answering, when set.
     */
    private function __construct(
        public array $body,
        public ?SatimConnectionException $exception = null,
    ) {}

    /**
     * A response body written out in full.
     *
     * @param  array<string, mixed>  $body
     */
    public static function make(array $body): self
    {
        return new self($body);
    }

    /**
     * The order was registered and the customer can be sent to the form.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function registered(
        string $orderId = 'V721uPPfNNofVQAAABL3',
        ?string $formUrl = null,
        array $overrides = [],
    ): self {
        return new self(array_merge([
            'errorCode' => '0',
            'orderId' => $orderId,
            'formUrl' => $formUrl ?? "https://test.satim.dz/payment/merchants/merchantsatim/payment.html?mdOrder=$orderId",
        ], $overrides));
    }

    /**
     * SATIM turned the registration down: no orderId, so no payment page.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function registerFailed(
        string $errorCode = '1',
        string $errorMessage = 'Order with given order number is already processed',
        array $overrides = [],
    ): self {
        return new self(array_merge([
            'errorCode' => $errorCode,
            'errorMessage' => $errorMessage,
        ], $overrides));
    }

    /**
     * The customer paid and the amount was deposited.
     *
     * @param  int|float  $amount  Amount in DZD, converted to the centimes SATIM reports.
     * @param  array<string, mixed>  $overrides
     */
    public static function paid(
        int|float $amount = 1000,
        string $orderNumber = 'K9m2X7qL4P',
        string $approvalCode = '913180',
        string $maskedCard = '6280****7215',
        string $message = 'Votre paiement a été accepté',
        array $overrides = [],
    ): self {
        return new self(array_merge(
            self::acknowledgeBody($amount, $orderNumber, $message),
            [
                'approvalCode' => $approvalCode,
                'authorizationResponseId' => $approvalCode,
                'Pan' => $maskedCard,
            ],
            $overrides,
        ));
    }

    /**
     * The payment was refused, which SATIM reports through respCode rather than
     * through a transport error.
     *
     * @param  int|float  $amount  Amount in DZD the customer tried to pay.
     * @param  array<string, mixed>  $overrides
     */
    public static function declined(
        string $respCode = '05',
        string $message = 'Votre transaction a été rejetée',
        int|float $amount = 1000,
        string $orderNumber = 'K9m2X7qL4P',
        array $overrides = [],
    ): self {
        return new self(array_merge(
            self::acknowledgeBody($amount, $orderNumber, $message),
            [
                'actionCode' => -1,
                'OrderStatus' => SatimOrderStatus::AuthorisationDeclined->value,
                'params' => ['respCode' => $respCode, 'respCode_desc' => $message],
            ],
            $overrides,
        ));
    }

    /**
     * The authorisation was reversed: SATIM reports no error of its own, so the
     * package supplies the wording the integration guide mandates.
     *
     * @param  int|float  $amount  Amount in DZD the authorisation covered.
     * @param  array<string, mixed>  $overrides
     */
    public static function reversed(
        int|float $amount = 1000,
        string $orderNumber = 'K9m2X7qL4P',
        array $overrides = [],
    ): self {
        return new self(array_merge(
            self::acknowledgeBody($amount, $orderNumber, 'Votre paiement a été accepté'),
            ['OrderStatus' => SatimOrderStatus::Reversed->value],
            $overrides,
        ));
    }

    /**
     * The order was refunded, whether from refund.do or from the back office.
     *
     * @param  int|float  $amount  Amount in DZD the order was placed for.
     * @param  array<string, mixed>  $overrides
     */
    public static function acknowledgeRefunded(
        int|float $amount = 1000,
        string $orderNumber = 'K9m2X7qL4P',
        array $overrides = [],
    ): self {
        return new self(array_merge(
            self::acknowledgeBody($amount, $orderNumber, 'Votre paiement a été accepté'),
            ['OrderStatus' => SatimOrderStatus::Refunded->value],
            $overrides,
        ));
    }

    /**
     * The money was returned to the customer.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function refunded(array $overrides = []): self
    {
        return new self(array_merge(['errorCode' => '0'], $overrides));
    }

    /**
     * SATIM refused the refund, which it reports with a non-zero code.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function refundFailed(
        string $errorCode = '7',
        string $errorMessage = 'Payment must be in a correct state',
        array $overrides = [],
    ): self {
        return new self(array_merge([
            'errorCode' => $errorCode,
            'errorMessage' => $errorMessage,
        ], $overrides));
    }

    /**
     * The gateway could not be reached, so the call raises instead of answering.
     */
    public static function connectionFailure(string $message = 'cURL error 28: Operation timed out'): self
    {
        return new self([], new SatimConnectionException($message));
    }

    /**
     * Fields every acknowledge answer carries, set to the accepted combination.
     * The callers above override only what makes their own case different.
     *
     * @return array<string, mixed>
     */
    private static function acknowledgeBody(int|float $amount, string $orderNumber, string $message): array
    {
        $amountInCentimes = (int) round($amount * 100);

        return [
            'ErrorCode' => '0',
            'ErrorMessage' => 'Success',
            'OrderStatus' => SatimOrderStatus::Deposited->value,
            'OrderNumber' => $orderNumber,
            'Amount' => $amountInCentimes,
            'depositAmount' => $amountInCentimes,
            'currency' => self::DZD_CURRENCY,
            'actionCode' => 0,
            'actionCodeDescription' => $message,
            'approvalCode' => '913180',
            'authorizationResponseId' => '913180',
            'Pan' => '6280****7215',
            'cardholderName' => 'CARDHOLDER NAME',
            'expiration' => '202701',
            'Ip' => '10.12.12.14',
            'params' => [
                'respCode' => '00',
                'respCode_desc' => $message,
                'udf1' => $orderNumber,
            ],
            'SvfeResponse' => '00',
        ];
    }
}
