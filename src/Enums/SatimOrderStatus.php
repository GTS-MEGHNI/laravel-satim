<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Enums;

/**
 * Order states SATIM reports, as listed in the integration guide.
 *
 * These are the values SATIM's merchant portal documents. A status outside this
 * list leaves the named status empty and keeps the raw number instead.
 *
 * A cancellation performed by staff inside SATIM's back office shows up as
 * Reversed, and a refund as Refunded, whether the refund came from the back
 * office or from refund.do.
 */
enum SatimOrderStatus: int
{
    /** Generic decline fallback. */
    case Declined = -1;

    /** Registered, but not paid. */
    case Registered = 0;

    /** Approved in one-phase, or held as a preauthorisation in two-phase. */
    case Approved = 1;

    /** Amount deposited: the only status that means the customer paid. */
    case Deposited = 2;

    /** Authorisation reversed, which is how a cancellation shows up. */
    case Reversed = 3;

    /** Transaction refunded. */
    case Refunded = 4;

    /** Authorisation declined. */
    case AuthorisationDeclined = 6;

    /**
     * The money reached the merchant.
     */
    public function isPaid(): bool
    {
        return $this === self::Deposited;
    }

    /**
     * Refunded from SATIM's back office.
     */
    public function isRefunded(): bool
    {
        return $this === self::Refunded;
    }

    /**
     * Cancelled from SATIM's back office: the authorisation was reversed.
     */
    public function isCancelled(): bool
    {
        return $this === self::Reversed;
    }

    /**
     * The money was taken back, whether by refund or by cancellation.
     */
    public function isReturned(): bool
    {
        return $this->isRefunded() || $this->isCancelled();
    }
}
