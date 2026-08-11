<?php

declare(strict_types=1);

use GtsMeghni\Satim\Data\SatimConfig;
use GtsMeghni\Satim\Enums\SatimLanguage;
use GtsMeghni\Satim\Exceptions\SatimConfigurationException;
use GtsMeghni\Satim\Tests\TestCase;

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function config_with(array $overrides): array
{
    return array_merge(TestCase::validConfig(), $overrides);
}

it('builds from a complete configuration', function () {
    $config = SatimConfig::fromArray(TestCase::validConfig());

    expect($config->baseUrl)->toBe('https://test2.satim.dz/payment/rest')
        ->and($config->username)->toBe('merchant-user')
        ->and($config->password)->toBe('merchant-secret')
        ->and($config->terminalId)->toBe('E0123456789')
        ->and($config->currency)->toBe('012')
        ->and($config->language)->toBe(SatimLanguage::FR)
        ->and($config->timeout)->toBe(30)
        ->and($config->connectTimeout)->toBe(10);
});

it('trims a trailing slash from the base url', function () {
    $config = SatimConfig::fromArray(config_with(['base_url' => 'https://test2.satim.dz/payment/rest/']));

    expect($config->baseUrl)->toBe('https://test2.satim.dz/payment/rest');
});

it('requires the base url to use https', function () {
    expect(fn () => SatimConfig::fromArray(config_with(['base_url' => 'http://test2.satim.dz/payment/rest'])))
        ->toThrow(SatimConfigurationException::class, 'it must use HTTPS');
});

it('requires the base url to be absolute', function () {
    expect(fn () => SatimConfig::fromArray(config_with(['base_url' => 'not-a-url'])))
        ->toThrow(SatimConfigurationException::class, 'it must be an absolute URL');
});

it('names the missing key', function (string $key) {
    expect(fn () => SatimConfig::fromArray(config_with([$key => null])))
        ->toThrow(SatimConfigurationException::class, "[satim.{$key}] is required but missing");
})->with(['base_url', 'username', 'password', 'terminal_id', 'return_url', 'fail_url']);

it('requires each redirect url to be absolute', function (string $key) {
    expect(fn () => SatimConfig::fromArray(config_with([$key => '/relative/redirect'])))
        ->toThrow(SatimConfigurationException::class, 'absolute URL SATIM can redirect to');
})->with(['return_url', 'fail_url']);

it('rejects a blank required value', function () {
    expect(fn () => SatimConfig::fromArray(config_with(['username' => '   '])))
        ->toThrow(SatimConfigurationException::class, '[satim.username] is required but missing');
});

it('rejects a terminal id longer than 16 characters', function () {
    expect(fn () => SatimConfig::fromArray(config_with(['terminal_id' => str_repeat('E', 17)])))
        ->toThrow(SatimConfigurationException::class, 'must not exceed 16 characters');
});

it('rejects a currency that is not three digits', function (string $currency) {
    expect(fn () => SatimConfig::fromArray(config_with(['currency' => $currency])))
        ->toThrow(SatimConfigurationException::class, 'ISO 4217 numeric code');
})->with(['12', '0012', 'DZD']);

it('rejects an unsupported language', function () {
    expect(fn () => SatimConfig::fromArray(config_with(['language' => 'ES'])))
        ->toThrow(SatimConfigurationException::class, 'supported languages are AR, FR and EN');
});

it('accepts a lowercase language', function () {
    expect(SatimConfig::fromArray(config_with(['language' => 'ar']))->language)->toBe(SatimLanguage::AR);
});

it('rejects a non positive timeout', function (string $key, mixed $seconds) {
    expect(fn () => SatimConfig::fromArray(config_with([$key => $seconds])))
        ->toThrow(SatimConfigurationException::class, "[satim.$key] is invalid");
})->with(['timeout', 'connect_timeout'])->with([0, -1, '30', null]);
