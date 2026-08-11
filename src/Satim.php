<?php

declare(strict_types=1);

namespace GtsMeghni\Satim;

use DateTimeImmutable;
use DateTimeInterface;
use GtsMeghni\Satim\Data\AcknowledgeResult;
use GtsMeghni\Satim\Data\Receipt;
use GtsMeghni\Satim\Data\RefundResult;
use GtsMeghni\Satim\Data\RegisterResult;
use GtsMeghni\Satim\Data\SatimConfig;
use GtsMeghni\Satim\Enums\SatimLanguage;
use GtsMeghni\Satim\Enums\SatimOperation;
use GtsMeghni\Satim\Events\SatimCallCompleted;
use GtsMeghni\Satim\Events\SatimCallFailed;
use GtsMeghni\Satim\Events\SatimCallStarted;
use GtsMeghni\Satim\Exceptions\SatimConfigurationException;
use GtsMeghni\Satim\Exceptions\SatimConnectionException;
use GtsMeghni\Satim\Exceptions\SatimValidationException;
use GtsMeghni\Satim\Http\SatimClient;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use JsonException;

final readonly class Satim
{
    /**
     * SATIM refuses payments below 50 DA.
     */
    public const int MINIMUM_AMOUNT_IN_CENTIMES = 5000;

    /**
     * Longest value SATIM accepts for each udf slot.
     */
    private const int UDF_MAX_LENGTH = 20;

    /**
     * ISO 4217 numeric code for the Algerian dinar.
     */
    private const string DZD_CURRENCY = '012';

    public function __construct(
        private SatimClient $client,
        private SatimConfig $config,
    ) {}

    /**
     * Register an order and obtain the hosted payment page URL.
     *
     * Persist the payment attempt BEFORE calling this, then store
     * $result->rawRequest and $result->rawResponse against it.
     *
     * A gateway-side rejection is reported through the returned result, not by
     * throwing.
     *
     * The return and fail URLs come from the satim config, which SATIM requires
     * on every order and which resolving this class already validated.
     *
     * @param  string  $orderNumber  Exactly 10 alphanumeric characters, unique per attempt.
     * @param  int|float|string  $amount  Amount in DZD; converted to centimes for the gateway.
     * @param  string|null  $udf1  Merchant reference echoed back on acknowledge; defaults to $orderNumber.
     * @param  string|null  $fundingTypeIndicator  Transaction type indicator, "CP" or "698".
     *
     * @throws SatimValidationException
     * @throws SatimConnectionException
     * @throws JsonException
     */
    public function register(
        string $orderNumber,
        int|float|string $amount,
        ?string $description = null,
        SatimLanguage|string|null $language = null,
        ?string $udf1 = null,
        ?string $udf2 = null,
        ?string $udf3 = null,
        ?string $udf4 = null,
        ?string $udf5 = null,
        ?string $fundingTypeIndicator = null,
    ): RegisterResult {
        if (preg_match('/^[A-Za-z0-9]{10}$/', $orderNumber) !== 1) {
            throw SatimValidationException::orderNumber($orderNumber);
        }

        $amountInCentimes = $this->toCentimes($amount);

        $payload = array_filter([
            'userName' => $this->config->username,
            'password' => $this->config->password,
            'orderNumber' => $orderNumber,
            'amount' => (string) $amountInCentimes,
            'currency' => $this->config->currency,
            'returnUrl' => $this->config->returnUrl,
            'failUrl' => $this->config->failUrl,
            'description' => $description,
            'language' => $this->resolveLanguage($language)->value,
            'jsonParams' => $this->jsonParams(
                udf: [
                    'udf1' => $udf1 ?? $orderNumber,
                    'udf2' => $udf2,
                    'udf3' => $udf3,
                    'udf4' => $udf4,
                    'udf5' => $udf5,
                ],
                fundingTypeIndicator: $fundingTypeIndicator,
            ),
        ], fn (?string $value): bool => $value !== null && $value !== '');

        $callId = (string) Str::uuid();

        $response = $this->send($callId, SatimOperation::Register, 'register.do', $payload, new SatimCallStarted(
            callId: $callId,
            operation: SatimOperation::Register,
            endpoint: 'register.do',
            payload: $this->redact($payload),
            orderNumber: $orderNumber,
            amountInCentimes: $amountInCentimes,
        ));

        $result = RegisterResult::fromResponse($response, $this->redact($payload));

        Event::dispatch(new SatimCallCompleted(
            callId: $callId,
            operation: SatimOperation::Register,
            response: $response,
            successful: $result->successful(),
            orderId: $result->orderId,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
        ));

        return $result;
    }

    /**
     * Confirm the final outcome of a payment after the customer comes back.
     *
     * SATIM cancels unconfirmed orders after a delay, so call this on both the
     * return and the fail URL rather than trusting the redirect alone.
     *
     * @param  string  $orderId  The orderId register returned, sent as mdOrder.
     * @param  SatimLanguage|string|null  $language  Language of the returned wording; defaults to config.
     *
     * @throws SatimValidationException
     * @throws SatimConnectionException
     */
    public function acknowledge(string $orderId, SatimLanguage|string|null $language = null): AcknowledgeResult
    {
        if (trim($orderId) === '') {
            throw SatimValidationException::missingOrderId();
        }

        $resolvedLanguage = $this->resolveLanguage($language);

        $payload = [
            'userName' => $this->config->username,
            'password' => $this->config->password,
            'mdOrder' => $orderId,
            'language' => $resolvedLanguage->value,
        ];

        $callId = (string) Str::uuid();
        $endpoint = 'public/acknowledgeTransaction.do';

        $response = $this->send($callId, SatimOperation::Acknowledge, $endpoint, $payload, new SatimCallStarted(
            callId: $callId,
            operation: SatimOperation::Acknowledge,
            endpoint: $endpoint,
            payload: $this->redact($payload),
            orderId: $orderId,
        ));

        $result = AcknowledgeResult::fromResponse(
            $orderId,
            $response,
            $this->redact($payload),
            $this->reversalMessage($resolvedLanguage),
        );

        Event::dispatch(new SatimCallCompleted(
            callId: $callId,
            operation: SatimOperation::Acknowledge,
            response: $response,
            successful: $result->paid(),
            orderId: $orderId,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
            orderStatus: $result->orderStatus,
        ));

        return $result;
    }

    /**
     * Return money already deposited for an order.
     *
     * SATIM allows several refunds against one order as long as they do not add
     * up to more than was deposited, and refuses a refund on an order that was
     * never charged. Your SATIM user needs the refund permission.
     *
     * @param  string  $orderId  The orderId register returned, also called mdOrder.
     * @param  int|float|string  $amount  Amount in DZD to return; converted to centimes.
     * @param  SatimLanguage|string|null  $language  Language of the returned message; defaults to config.
     *
     * @throws SatimValidationException
     * @throws SatimConnectionException
     */
    public function refund(
        string $orderId,
        int|float|string $amount,
        SatimLanguage|string|null $language = null,
    ): RefundResult {
        if (trim($orderId) === '') {
            throw SatimValidationException::missingOrderId();
        }

        $amountInCentimes = $this->toCentimes($amount);

        // SATIM's own parameter table requires only these four, and its example
        // sends nothing else. Language is added for a localised message; the
        // refund uses the currency the order was placed in.
        $payload = [
            'userName' => $this->config->username,
            'password' => $this->config->password,
            'orderId' => $orderId,
            'amount' => (string) $amountInCentimes,
            'language' => $this->resolveLanguage($language)->value,
        ];

        $callId = (string) Str::uuid();

        $response = $this->send($callId, SatimOperation::Refund, 'refund.do', $payload, new SatimCallStarted(
            callId: $callId,
            operation: SatimOperation::Refund,
            endpoint: 'refund.do',
            payload: $this->redact($payload),
            orderId: $orderId,
            amountInCentimes: $amountInCentimes,
        ));

        $result = RefundResult::fromResponse($orderId, $amountInCentimes, $response, $this->redact($payload));

        Event::dispatch(new SatimCallCompleted(
            callId: $callId,
            operation: SatimOperation::Refund,
            response: $response,
            successful: $result->successful(),
            orderId: $orderId,
            errorCode: $result->errorCode,
            errorMessage: $result->errorMessage,
        ));

        return $result;
    }

    /**
     * Build the contents of a payment receipt.
     *
     * Build it once after confirming the payment and store it, then pass the
     * stored date back on later requests: your spec requires a reprint to be the
     * original receipt, not a freshly dated one.
     *
     * @param  DateTimeInterface|null  $paidAt  Defaults to now, which is the moment you confirmed.
     * @param  SatimLanguage|string|null  $language  Defaults to the language of the confirmation.
     *
     * @throws SatimValidationException
     * @throws SatimConfigurationException
     */
    public function receipt(
        AcknowledgeResult $result,
        ?DateTimeInterface $paidAt = null,
        SatimLanguage|string|null $language = null,
    ): Receipt {
        if ($result->rejected()) {
            throw SatimValidationException::receiptForUnpaidTransaction($result->orderId);
        }

        if ($this->config->receiptLogo === null) {
            throw SatimConfigurationException::missing('receipt.logo');
        }

        $resolvedLanguage = $this->resolveLanguage($language);

        return new Receipt(
            orderId: $result->orderId,
            orderNumber: $result->orderNumber,
            approvalCode: $result->approvalCode,
            paidAt: $paidAt === null
                ? Date::now()->toDateTimeImmutable()
                : DateTimeImmutable::createFromInterface($paidAt),
            amount: $result->amount,
            amountInCentimes: $result->amountInCentimes,
            currency: $result->currency,
            currencyLabel: $this->currencyLabel($result->currency),
            maskedCard: $result->maskedCard,
            cardholderName: $result->cardholderName,
            message: $result->message,
            language: $resolvedLanguage,
            supportMessage: $this->supportMessage($resolvedLanguage),
            logo: $this->config->receiptLogo,
        );
    }

    /**
     * SATIM answers with an ISO currency number, which means nothing on a
     * receipt. Only DZD is in use, so anything else is shown as sent.
     */
    private function currencyLabel(?string $currency): string
    {
        return $currency === self::DZD_CURRENCY ? 'DZD' : (string) $currency;
    }

    /**
     * Hotline text, which the spec requires beside the SATIM logo and never on
     * its own.
     */
    private function supportMessage(SatimLanguage $language): string
    {
        $message = Lang::get('satim::messages.support_message', [], mb_strtolower($language->value));

        return is_string($message) ? $message : '';
    }

    /**
     * Follows the language of the call, not the application locale, so the
     * wording matches what the customer saw on the payment page.
     */
    private function reversalMessage(SatimLanguage $language): string
    {
        $message = Lang::get('satim::messages.transaction_rejected', [], mb_strtolower($language->value));

        return is_string($message) ? $message : '';
    }

    /**
     * @param  array<string, string|null>  $udf
     *
     * @throws SatimValidationException
     * @throws JsonException
     */
    private function jsonParams(array $udf, ?string $fundingTypeIndicator): string
    {
        $params = ['force_terminal_id' => $this->config->terminalId];

        foreach ($udf as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if (mb_strlen($value) > self::UDF_MAX_LENGTH) {
                throw SatimValidationException::udfTooLong($field, mb_strlen($value));
            }

            $params[$field] = $value;
        }

        if ($fundingTypeIndicator !== null && $fundingTypeIndicator !== '') {
            $params['fundingTypeIndicator'] = $fundingTypeIndicator;
        }

        return json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    /**
     * Announce the call, send it, and announce a failure that never came back.
     *
     * The announcement goes out before the request leaves, so a call that dies
     * in flight is still on record.
     *
     * @param  array<string, string>  $payload
     * @return array<string, mixed>
     *
     * @throws SatimConnectionException
     */
    private function send(
        string $callId,
        SatimOperation $operation,
        string $endpoint,
        array $payload,
        SatimCallStarted $started,
    ): array {
        Event::dispatch($started);

        try {
            return $this->client->post($endpoint, $payload);
        } catch (SatimConnectionException $e) {
            Event::dispatch(new SatimCallFailed($callId, $operation, $e->getMessage()));

            throw $e;
        }
    }

    /**
     * SATIM expects centimes; business amounts stay decimal on the caller side.
     *
     * @throws SatimValidationException
     */
    private function toCentimes(int|float|string $amount): int
    {
        if (is_string($amount)) {
            if (! is_numeric(trim($amount))) {
                throw SatimValidationException::nonNumericAmount($amount);
            }

            $amount = (float) trim($amount);
        }

        $centimes = (int) round((float) $amount * 100);

        if ($centimes < self::MINIMUM_AMOUNT_IN_CENTIMES) {
            throw SatimValidationException::amountBelowMinimum($centimes, self::MINIMUM_AMOUNT_IN_CENTIMES);
        }

        return $centimes;
    }

    /**
     * @throws SatimValidationException
     */
    private function resolveLanguage(SatimLanguage|string|null $language): SatimLanguage
    {
        if ($language === null) {
            return $this->config->language;
        }

        if ($language instanceof SatimLanguage) {
            return $language;
        }

        return SatimLanguage::tryFrom(mb_strtoupper($language))
            ?? throw SatimValidationException::language($language);
    }

    /**
     * Keep credentials out of the payload callers are expected to persist.
     *
     * @param  array<string, string>  $payload
     * @return array<string, string>
     */
    private function redact(array $payload): array
    {
        return array_merge($payload, [
            'userName' => '[REDACTED]',
            'password' => '[REDACTED]',
        ]);
    }
}
