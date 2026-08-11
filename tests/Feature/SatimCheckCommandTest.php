<?php

declare(strict_types=1);

it('reports a valid configuration', function () {
    $this->artisan('satim:check')
        ->expectsOutputToContain('https://test2.satim.dz/payment/rest')
        ->expectsOutputToContain('E0123456789')
        ->expectsOutputToContain('The SATIM configuration is valid.')
        ->assertSuccessful();
});

it('masks the password', function () {
    $this->artisan('satim:check')
        ->expectsOutputToContain('********')
        ->doesntExpectOutputToContain('merchant-secret')
        ->assertSuccessful();
});

it('fails and names the offending key when the configuration is incomplete', function () {
    config()->set('satim.terminal_id', null);

    $this->artisan('satim:check')
        ->expectsOutputToContain('[satim.terminal_id] is required but missing')
        ->assertFailed();
});

it('fails when the base url is not https', function () {
    config()->set('satim.base_url', 'http://test2.satim.dz/payment/rest');

    $this->artisan('satim:check')
        ->expectsOutputToContain('it must use HTTPS')
        ->assertFailed();
});
