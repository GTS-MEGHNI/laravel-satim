<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Enums;

/**
 * Error codes SATIM returns, as listed in the integration guide.
 *
 * The same number does not mean the same thing on every endpoint: 2 is only
 * ever returned by acknowledge, 1, 3, 4 and 14 only by register, and 5 and 7
 * cover different faults depending on the call. Read a code through
 * description(), which asks for the operation for that reason.
 *
 * SATIM also sends its own message, in the language of the request. That text
 * is what a customer should see; this enum is for deciding what your code does
 * next. A code outside this list leaves the raw value untouched on the result.
 */
enum SatimErrorCode: string
{
    /** No system error: the call did what it was asked. */
    case None = '0';

    /** Register only: the order number was already used, or the submerchant is blocked. */
    case OrderAlreadyProcessed = '1';

    /** Acknowledge only: the payment was declined over the card credentials. */
    case Declined = '2';

    /** Register only: the currency is not one SATIM knows. */
    case UnknownCurrency = '3';

    /** Register only: a mandatory parameter was not sent at all. */
    case MissingParameter = '4';

    /** A parameter value was refused, or the merchant may not make this call. */
    case InvalidParameter = '5';

    /** No order exists for the id that was sent. */
    case UnknownOrder = '6';

    /** A fault on SATIM's side, or an order in a state the call does not allow. */
    case SystemError = '7';

    /** Register only: the payment way is not valid. */
    case InvalidPaymentWay = '14';

    /**
     * The gateway reported no error.
     *
     * On register and refund this is the success criterion. On acknowledge it
     * is not: a payment can be declined while the call itself succeeded, which
     * is why AcknowledgeResult reads three fields rather than this one.
     */
    public function successful(): bool
    {
        return $this === self::None;
    }

    /**
     * What SATIM's guide says this code means on that call.
     *
     * Several codes cover more than one fault, and the guide lists them all,
     * so the wording here names each of them.
     */
    public function description(SatimOperation $operation): string
    {
        return match ($this) {
            self::None => 'No system error.',
            self::OrderAlreadyProcessed => 'The order number was already processed, or was registered and not paid, or the submerchant is blocked or deleted.',
            self::Declined => 'The order was declined because of an error in the payment credentials.',
            self::UnknownCurrency => 'Unknown currency.',
            self::MissingParameter => 'A mandatory parameter was not specified: order number, merchant user name, amount, return URL, or password.',
            self::InvalidParameter => $this->invalidParameterDescription($operation),
            self::UnknownOrder => 'Unregistered order id.',
            self::SystemError => $operation === SatimOperation::Refund
                ? 'System error, or the payment is not in a state that allows a refund.'
                : 'System error.',
            self::InvalidPaymentWay => 'The payment way is invalid.',
        };
    }

    /**
     * Code 5 is the busiest one, and its list differs on every endpoint.
     */
    private function invalidParameterDescription(SatimOperation $operation): string
    {
        return match ($operation) {
            SatimOperation::Register => 'Incorrect value of a request parameter, an incorrect language, invalid jsonParams, access denied, or the merchant must change the password.',
            SatimOperation::Acknowledge => 'Access denied, the user must change the password, or the order id was empty.',
            SatimOperation::Refund => 'Access denied, the user must change the password, or the refund amount is not allowed.',
        };
    }
}
