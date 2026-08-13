<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Listeners;

use GtsMeghni\Satim\Events\SatimCallCompleted;
use GtsMeghni\Satim\Events\SatimCallFailed;
use GtsMeghni\Satim\Events\SatimCallStarted;
use GtsMeghni\Satim\Models\SatimCall;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;

/**
 * Writes the audit trail, unless it is switched off in config.
 *
 * This is the only part of the package that touches a database. Switch it off
 * and the gateway calls run without a single query; replace it with your own
 * listener on the same events to store the trail your own way.
 */
class SatimCallRecorder
{
    /**
     * Record the call before it is sent, so nothing can be lost in flight.
     */
    public function started(SatimCallStarted $event): void
    {
        if ($this->disabled()) {
            return;
        }

        SatimCall::query()->create([
            'call_id' => $event->callId,
            'operation' => $event->operation,
            'order_number' => $event->orderNumber,
            'order_id' => $event->orderId,
            'amount_in_centimes' => $event->amountInCentimes,
            'request' => $event->payload,
            'created_at' => Date::now(),
        ]);
    }

    /**
     * Fill in the answer. The order id is written here too: on a register call
     * it does not exist until SATIM replies.
     */
    public function completed(SatimCallCompleted $event): void
    {
        if ($this->disabled()) {
            return;
        }

        SatimCall::query()
            ->where('call_id', $event->callId)
            ->update(array_filter([
                'order_id' => $event->orderId,
            ], fn (?string $value): bool => $value !== null) + [
                'response' => json_encode($event->response, JSON_THROW_ON_ERROR),
                'successful' => $event->successful,
                'error_code' => $event->errorCode,
                'error_message' => $event->errorMessage,
                'order_status' => $event->orderStatus,
                'completed_at' => Date::now(),
            ]);
    }

    /**
     * No usable answer arrived. The row stays without a completion time, which
     * is what marks it as needing a human to check with SATIM.
     */
    public function failed(SatimCallFailed $event): void
    {
        if ($this->disabled()) {
            return;
        }

        SatimCall::query()
            ->where('call_id', $event->callId)
            ->update([
                'successful' => false,
                'failure_reason' => $event->reason,
            ]);
    }

    /**
     * Checked on every event, so changing the setting takes effect at once and
     * a disabled trail runs no queries at all.
     */
    private function disabled(): bool
    {
        return ! Config::boolean('satim.audit.enabled', true);
    }
}
