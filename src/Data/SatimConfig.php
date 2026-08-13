<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Data;

use GtsMeghni\Satim\Enums\SatimLanguage;
use GtsMeghni\Satim\Exceptions\SatimConfigurationException;

/**
 * Validated snapshot of the satim config file.
 *
 * Built lazily by the service provider, so an unconfigured application still
 * boots and every gateway call fails with a named key instead of letting SATIM
 * reject a half-empty request.
 */
final readonly class SatimConfig
{
    /**
     * Longest terminal id SATIM accepts for force_terminal_id.
     */
    private const int TERMINAL_ID_MAX_LENGTH = 16;

    public function __construct(
        public string $baseUrl,
        public string $username,
        public string $password,
        public string $terminalId,
        public string $currency,
        public SatimLanguage $language,
        public int $timeout,
        public int $connectTimeout,
        public string $returnUrl,
        public string $failUrl,
        public ?string $receiptLogo = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @throws SatimConfigurationException
     */
    public static function fromArray(array $config): self
    {
        return new self(
            baseUrl: self::baseUrl($config),
            username: self::requiredString($config, 'username'),
            password: self::requiredString($config, 'password'),
            terminalId: self::terminalId($config),
            currency: self::currency($config),
            language: self::language($config),
            timeout: self::seconds($config, 'timeout'),
            connectTimeout: self::seconds($config, 'connect_timeout'),
            returnUrl: self::redirectUrl($config, 'return_url'),
            failUrl: self::redirectUrl($config, 'fail_url'),
            receiptLogo: self::receiptLogo($config),
        );
    }

    /**
     * A redirect URL SATIM sends the customer back to.
     *
     * Required rather than optional: SATIM marks both URLs mandatory on every
     * order, and they belong to the environment, not to a single payment.
     *
     * @param  array<array-key, mixed>  $config
     *
     * @throws SatimConfigurationException
     */
    private static function redirectUrl(array $config, string $key): string
    {
        $url = self::requiredString($config, $key);

        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            throw SatimConfigurationException::invalid($key, 'it must be an absolute URL SATIM can redirect to.');
        }

        return $url;
    }

    /**
     * Optional: payments work without it, receipts do not.
     *
     * @param  array<array-key, mixed>  $config
     */
    private static function receiptLogo(array $config): ?string
    {
        $receipt = $config['receipt'] ?? null;

        if (! is_array($receipt)) {
            return null;
        }

        $logo = $receipt['logo'] ?? null;

        if (! is_string($logo) || trim($logo) === '') {
            return null;
        }

        return trim($logo);
    }

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @throws SatimConfigurationException
     */
    private static function baseUrl(array $config): string
    {
        $baseUrl = rtrim(self::requiredString($config, 'base_url'), '/');

        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false) {
            throw SatimConfigurationException::invalid('base_url', 'it must be an absolute URL.');
        }

        if (parse_url($baseUrl, PHP_URL_SCHEME) !== 'https') {
            throw SatimConfigurationException::invalid('base_url', 'it must use HTTPS.');
        }

        return $baseUrl;
    }

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @throws SatimConfigurationException
     */
    private static function terminalId(array $config): string
    {
        $terminalId = self::requiredString($config, 'terminal_id');

        if (mb_strlen($terminalId) > self::TERMINAL_ID_MAX_LENGTH) {
            throw SatimConfigurationException::invalid(
                'terminal_id',
                'it must not exceed '.self::TERMINAL_ID_MAX_LENGTH.' characters.',
            );
        }

        return $terminalId;
    }

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @throws SatimConfigurationException
     */
    private static function currency(array $config): string
    {
        $currency = self::requiredString($config, 'currency');

        if (preg_match('/^\d{3}$/', $currency) !== 1) {
            throw SatimConfigurationException::invalid(
                'currency',
                'it must be a 3 digit ISO 4217 numeric code, such as "012" for DZD.',
            );
        }

        return $currency;
    }

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @throws SatimConfigurationException
     */
    private static function language(array $config): SatimLanguage
    {
        $language = self::requiredString($config, 'language');

        return SatimLanguage::tryFrom(mb_strtoupper($language))
            ?? throw SatimConfigurationException::invalid('language', 'supported languages are AR, FR and EN.');
    }

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @throws SatimConfigurationException
     */
    private static function seconds(array $config, string $key): int
    {
        $seconds = $config[$key] ?? null;

        if (! is_int($seconds) || $seconds < 1) {
            throw SatimConfigurationException::invalid($key, 'it must be a positive integer number of seconds.');
        }

        return $seconds;
    }

    /**
     * @param  array<array-key, mixed>  $config
     *
     * @throws SatimConfigurationException
     */
    private static function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw SatimConfigurationException::missing($key);
        }

        return trim($value);
    }
}
