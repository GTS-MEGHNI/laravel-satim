<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Exceptions;

/**
 * Thrown when arguments would violate a documented SATIM request constraint.
 *
 * These are caught before any HTTP call so the gateway never has to reject the
 * request for a rule the package can check locally.
 */
class SatimValidationException extends SatimException
{
    public static function orderNumber(string $orderNumber): self
    {
        return new self(
            "The SATIM order number [$orderNumber] is invalid: it must be exactly 10 alphanumeric characters.",
        );
    }

    public static function orderNumberUnavailable(int $attempts): self
    {
        return new self(
            "No unused SATIM order number was found in $attempts attempts, which should not happen: "
            .'check the audit trail for a flood of registrations.',
        );
    }

    public static function nonNumericAmount(string $amount): self
    {
        return new self("The SATIM amount [$amount] is invalid: it must be numeric.");
    }

    public static function amountBelowMinimum(int $centimes, int $minimum): self
    {
        return new self(
            "The SATIM amount of $centimes centimes is below the gateway minimum of $minimum centimes.",
        );
    }

    public static function receiptForUnpaidTransaction(string $orderId): self
    {
        return new self(
            "A receipt cannot be built for order [$orderId] because the payment was not accepted.",
        );
    }

    public static function missingOrderId(): self
    {
        return new self('The SATIM order id is required and cannot be empty.');
    }

    public static function udfTooLong(string $field, int $length): self
    {
        return new self("The SATIM parameter [$field] is $length characters long; the maximum is 20.");
    }

    public static function language(string $language): self
    {
        return new self("The SATIM language [$language] is invalid: supported languages are AR, FR and EN.");
    }
}
