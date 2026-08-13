<?php

declare(strict_types=1);

use GtsMeghni\Satim\Enums\SatimOperation;
use GtsMeghni\Satim\Models\SatimCall;
use Illuminate\Support\Facades\Date;

/**
 * One recorded call, as old as the test needs it.
 */
function recordedCall(string $callId, int $daysAgo): SatimCall
{
    return SatimCall::query()->create([
        'call_id' => $callId,
        'operation' => SatimOperation::Register,
        'order_id' => "order-$callId",
        'request' => [],
        'successful' => true,
        'created_at' => Date::now()->subDays($daysAgo),
        'completed_at' => Date::now()->subDays($daysAgo),
    ]);
}

it('ships a ten year retention, matching the commercial retention period', function () {
    $config = require __DIR__.'/../../config/satim.php';

    expect($config['audit']['retention'])->toBe(3650);
});

it('keeps everything when the retention is set to null', function () {
    config()->set('satim.audit.retention', null);
    recordedCall('old', 3650);

    $this->artisan('model:prune', ['--model' => [SatimCall::class]])->assertSuccessful();

    expect(SatimCall::query()->count())->toBe(1);
});

it('deletes rows older than the configured retention', function () {
    config()->set('satim.audit.retention', 365);
    recordedCall('old', 400);
    recordedCall('recent', 30);

    $this->artisan('model:prune', ['--model' => [SatimCall::class]])->assertSuccessful();

    expect(SatimCall::query()->pluck('call_id')->all())->toBe(['recent']);
});

it('accepts a retention set through the environment as a string', function () {
    config()->set('satim.audit.retention', '365');
    recordedCall('old', 400);

    $this->artisan('model:prune', ['--model' => [SatimCall::class]])->assertSuccessful();

    expect(SatimCall::query()->count())->toBe(0);
});

it('keeps everything when the retention is not a usable number', function (mixed $retention) {
    config()->set('satim.audit.retention', $retention);
    recordedCall('old', 3650);

    $this->artisan('model:prune', ['--model' => [SatimCall::class]])->assertSuccessful();

    expect(SatimCall::query()->count())->toBe(1);
})->with([null, '', 'forever', 0, -5]);
