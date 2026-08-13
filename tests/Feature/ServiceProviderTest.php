<?php

declare(strict_types=1);

use GtsMeghni\Satim\Contracts\SatimTransport;
use GtsMeghni\Satim\Http\SatimClient;
use GtsMeghni\Satim\Satim;
use Illuminate\Contracts\Console\Kernel;

it('resolves the singleton', function () {
    expect(app(Satim::class))->toBeInstanceOf(Satim::class);
});

it('resolves the http client as the transport', function () {
    expect(app(SatimTransport::class))->toBeInstanceOf(SatimClient::class)
        ->and(app(SatimTransport::class))->toBe(app(SatimTransport::class));
});

it('returns the same instance from the container', function () {
    expect(app(Satim::class))->toBe(app(Satim::class));
});

it('merges the package config', function () {
    expect(config('satim.currency'))->toBe('012');
});

it('loads the package translations', function () {
    expect(trans('satim::messages.support_message'))->toContain('3020');
});

it('registers the artisan command', function () {
    expect(app(Kernel::class)->all())->toHaveKey('satim:check');
});
