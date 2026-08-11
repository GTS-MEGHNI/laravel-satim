<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Data;

use DateTimeImmutable;
use GtsMeghni\Satim\Enums\SatimLanguage;

/**
 * Contents of a payment receipt.
 *
 * Holds every field the SATIM certification checklist expects on a receipt, and
 * nothing that renders it: the page, the PDF and the email are the
 * application's, so it can brand them freely.
 *
 * Build it once after the payment is confirmed and store it. Reusing the stored
 * paidAt is what makes a reprint identical to the original.
 */
final readonly class Receipt
{
    /**
     * CIB and Edahabia are the same terminal and the same flow, so the receipt
     * carries one label rather than detecting a card type.
     */
    public const string PAYMENT_METHOD = 'CIB/EDAHABIA';

    /**
     * @param  string|null  $orderId  SATIM transaction identifier, also called mdOrder.
     * @param  string|null  $message  SATIM's own description of the payment.
     * @param  string  $supportMessage  Hotline text, in the receipt's language.
     * @param  string  $logo  SATIM logo, which must never be shown apart from the hotline text.
     */
    public function __construct(
        public ?string $orderId,
        public ?string $orderNumber,
        public ?string $approvalCode,
        public DateTimeImmutable $paidAt,
        public ?float $amount,
        public ?int $amountInCentimes,
        public ?string $currency,
        public string $currencyLabel,
        public ?string $maskedCard,
        public ?string $cardholderName,
        public ?string $message,
        public SatimLanguage $language,
        public string $supportMessage,
        public string $logo,
        public string $paymentMethod = self::PAYMENT_METHOD,
    ) {}

    /**
     * Locale of this receipt, so a reprint reads exactly as the original.
     */
    public function locale(): string
    {
        return mb_strtolower($this->language->value);
    }

    /**
     * Arabic receipts have to read right to left.
     */
    public function direction(): string
    {
        return $this->language === SatimLanguage::AR ? 'rtl' : 'ltr';
    }
}
