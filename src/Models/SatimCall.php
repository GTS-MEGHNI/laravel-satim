<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Models;

use GtsMeghni\Satim\Data\SatimOrderState;
use GtsMeghni\Satim\Enums\SatimOperation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;

/**
 * A single recorded call to SATIM.
 *
 * Written once and completed once, never edited afterwards, which is why there
 * is no updated_at column.
 *
 * @property int $id
 * @property string $call_id
 * @property SatimOperation $operation
 * @property string|null $order_number
 * @property string|null $order_id
 * @property int|null $amount_in_centimes
 * @property array<string, mixed> $request
 * @property array<string, mixed>|null $response
 * @property bool|null $successful
 * @property string|null $error_code
 * @property string|null $error_message
 * @property int|null $order_status
 * @property string|null $failure_reason
 * @property Carbon $created_at
 * @property Carbon|null $completed_at
 */
class SatimCall extends Model
{
    use Prunable;

    /**
     * Rows are never updated after completion, so there is nothing to stamp.
     */
    public const ?string UPDATED_AT = null;

    protected $guarded = [];

    public function getTable(): string
    {
        return Config::string('satim.audit.table', 'satim_calls');
    }

    /**
     * Rows old enough to delete, for `php artisan model:prune`.
     *
     * Ten years by default, following article 12 of the Algerian code de
     * commerce. A retention of null keeps everything, and nothing is deleted
     * at all unless the prune command is scheduled.
     *
     * @return Builder<SatimCall>
     */
    public function prunable(): Builder
    {
        // Read loosely on purpose: a retention set through the environment
        // arrives as a string, and a blank one must mean "keep everything"
        // rather than "delete today".
        $days = Config::get('satim.audit.retention');
        $days = is_string($days) && is_numeric($days) ? (int) $days : $days;

        if (! is_int($days) || $days < 1) {
            return self::query()->whereRaw('1 = 0');
        }

        return self::query()->where('created_at', '<', Date::now()->subDays($days));
    }

    /**
     * Everything recorded for one SATIM order, oldest call first.
     */
    public static function stateFor(string $orderId): SatimOrderState
    {
        /** @var Collection<int, self> $calls */
        $calls = self::query()->where('order_id', $orderId)->orderBy('id')->get();

        return new SatimOrderState($orderId, $calls);
    }

    /**
     * @param  Builder<SatimCall>  $query
     * @return Builder<SatimCall>
     */
    public function scopeForOrder(Builder $query, string $orderId): Builder
    {
        return $query->where('order_id', $orderId);
    }

    /**
     * @param  Builder<SatimCall>  $query
     * @return Builder<SatimCall>
     */
    public function scopeForOrderNumber(Builder $query, string $orderNumber): Builder
    {
        return $query->where('order_number', $orderNumber);
    }

    /**
     * @param  Builder<SatimCall>  $query
     * @return Builder<SatimCall>
     */
    public function scopeOperation(Builder $query, SatimOperation $operation): Builder
    {
        return $query->where('operation', $operation->value);
    }

    /**
     * @param  Builder<SatimCall>  $query
     * @return Builder<SatimCall>
     */
    public function scopeSuccessful(Builder $query): Builder
    {
        return $query->where('successful', true);
    }

    /**
     * The call finished, whether or not the outcome was what you wanted.
     *
     * @param  Builder<SatimCall>  $query
     * @return Builder<SatimCall>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->whereNotNull('completed_at');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => SatimOperation::class,
            'request' => 'array',
            'response' => 'array',
            'successful' => 'boolean',
            'amount_in_centimes' => 'integer',
            'order_status' => 'integer',
            'created_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }
}
