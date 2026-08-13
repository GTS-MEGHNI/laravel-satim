<?php

declare(strict_types=1);

use GtsMeghni\Satim\Data\AcknowledgeResult;
use GtsMeghni\Satim\Data\Receipt;
use GtsMeghni\Satim\Enums\SatimLanguage;
use GtsMeghni\Satim\Exceptions\SatimConfigurationException;
use GtsMeghni\Satim\Exceptions\SatimValidationException;
use GtsMeghni\Satim\Facades\Satim;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Http;

function paidTransaction(): AcknowledgeResult
{
    fakeAcknowledge(acknowledgeResponse());

    return Satim::acknowledge(ORDER_ID);
}

it('carries every field the receipt must show', function () {
    $receipt = Satim::receipt(paidTransaction());

    expect($receipt)
        ->orderId->toBe(ORDER_ID)
        ->orderNumber->toBe('CMD0000004')
        ->approvalCode->toBe('913180')
        ->amount->toBe(1003.20)
        ->amountInCentimes->toBe(100320)
        ->currency->toBe('012')
        ->currencyLabel->toBe('DZD')
        ->maskedCard->toBe('6280****7215')
        ->cardholderName->toBe('cardholder Name')
        ->message->toBe('Votre paiement a été accepté')
        ->paymentMethod->toBe('CIB/EDAHABIA');
});

it('shows the currency as sent when it is not the dinar', function () {
    fakeAcknowledge(acknowledgeResponse(['currency' => '840']));

    expect(Satim::receipt(Satim::acknowledge(ORDER_ID))->currencyLabel)->toBe('840');
});

it('refuses to build a receipt for a payment that was not accepted', function () {
    fakeAcknowledge(acknowledgeResponse(['OrderStatus' => 3]));
    $rejected = Satim::acknowledge(ORDER_ID);

    expect(fn () => Satim::receipt($rejected))
        ->toThrow(SatimValidationException::class, 'because the payment was not accepted');
});

it('refuses to build a receipt when the logo is not configured', function () {
    config()->set('satim.receipt.logo', null);
    $transaction = paidTransaction();

    expect(fn () => Satim::receipt($transaction))
        ->toThrow(SatimConfigurationException::class, '[satim.receipt.logo] is required but missing');
});

it('carries the logo and the hotline message together', function () {
    $receipt = Satim::receipt(paidTransaction());

    expect($receipt->logo)->toBe('/vendor/satim/satim.png')
        ->and($receipt->supportMessage)->toContain('3020');
});

it('uses the bundled logo by default', function () {
    expect(config('satim.receipt.logo'))->toBe('/vendor/satim/satim.png')
        ->and(Satim::receipt(paidTransaction())->logo)->toBe('/vendor/satim/satim.png');
});

it('ships the logo assets the spec requires', function () {
    expect(__DIR__.'/../../public/satim.png')->toBeReadableFile()
        ->and(__DIR__.'/../../public/cib-edahabia-logo.png')->toBeReadableFile();
});

it('gives the hotline message in the language of the receipt', function (SatimLanguage $language, string $expected) {
    $receipt = Satim::receipt(paidTransaction(), language: $language);

    expect($receipt->supportMessage)->toBe($expected)
        ->and($receipt->language)->toBe($language);
})->with([
    [SatimLanguage::EN, 'If you have a payment issue, please contact SATIM hotline 3020'],
    [SatimLanguage::FR, 'En cas de problème de paiement, veuillez contacter la hotline SATIM 3020'],
    [SatimLanguage::AR, 'إذا واجهت مشكلة في الدفع، يرجى الاتصال بالخط الساخن لساتيم 3020'],
]);

it('uses the configured language by default', function () {
    expect(Satim::receipt(paidTransaction())->language)->toBe(SatimLanguage::FR);
});

it('reads right to left only in arabic', function (SatimLanguage $language, string $direction, string $locale) {
    $receipt = Satim::receipt(paidTransaction(), language: $language);

    expect($receipt->direction())->toBe($direction)
        ->and($receipt->locale())->toBe($locale);
})->with([
    [SatimLanguage::AR, 'rtl', 'ar'],
    [SatimLanguage::FR, 'ltr', 'fr'],
    [SatimLanguage::EN, 'ltr', 'en'],
]);

it('dates the receipt at the moment the payment was confirmed', function () {
    Date::setTestNow('2026-08-11 14:30:00');

    expect(Satim::receipt(paidTransaction())->paidAt->format('Y-m-d H:i:s'))->toBe('2026-08-11 14:30:00');
});

it('accepts a stored date so a reprint matches the original', function () {
    $original = new DateTimeImmutable('2026-08-01 09:15:00');

    $reprint = Satim::receipt(paidTransaction(), paidAt: $original);

    expect($reprint->paidAt->format('Y-m-d H:i:s'))->toBe('2026-08-01 09:15:00');
});

it('builds the same receipt twice when the stored date is reused', function () {
    $transaction = paidTransaction();
    $paidAt = new DateTimeImmutable('2026-08-01 09:15:00');

    expect(Satim::receipt($transaction, paidAt: $paidAt))
        ->toEqual(Satim::receipt($transaction, paidAt: $paidAt));
});

it('needs no further gateway calls', function () {
    $transaction = paidTransaction();
    Http::fake();

    Satim::receipt($transaction);

    Http::assertNothingSent();
});

it('exposes the payment method as a single label for both card types', function () {
    expect(Receipt::PAYMENT_METHOD)->toBe('CIB/EDAHABIA');
});
