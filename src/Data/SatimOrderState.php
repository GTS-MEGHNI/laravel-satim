<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Data;

use GtsMeghni\Satim\Enums\SatimOperation;
use GtsMeghni\Satim\Enums\SatimOrderStatus;
use GtsMeghni\Satim\Models\SatimCall;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where an order stands, worked out from the calls recorded against it.
 *
 * Nothing is stored twice: there is no status column that could disagree with
 * the log, and a failed call is part of the picture rather than an absence.
 */
final readonly class SatimOrderState
{
    /**
     * @param  Collection<int, SatimCall>  $calls  Every recorded call, oldest first.
     */
    public function __construct(
        public string $orderId,
        public Collection $calls,
    ) {}

    /**
     * SATIM accepted the order and gave it an identifier.
     */
    public function registered(): bool
    {
        return $this->successful(SatimOperation::Register)->isNotEmpty();
    }

    /**
     * The outcome was confirmed with SATIM, whatever that outcome was.
     */
    public function acknowledged(): bool
    {
        return $this->completed(SatimOperation::Acknowledge)->isNotEmpty();
    }

    /**
     * The customer paid and the money was deposited.
     */
    public function paid(): bool
    {
        return $this->successful(SatimOperation::Acknowledge)->isNotEmpty();
    }

    /**
     * At least one refund went through, whether partial or in full.
     */
    public function refunded(): bool
    {
        return $this->successful(SatimOperation::Refund)->isNotEmpty();
    }

    /**
     * Total returned so far, which SATIM caps at the amount deposited.
     */
    public function refundedInCentimes(): int
    {
        $total = 0;

        foreach ($this->successful(SatimOperation::Refund) as $refund) {
            $total += $refund->amount_in_centimes ?? 0;
        }

        return $total;
    }

    /**
     * The last status SATIM reported, or null if it never reported one.
     */
    public function orderStatus(): ?SatimOrderStatus
    {
        $status = $this->completed(SatimOperation::Acknowledge)
            ->reverse()
            ->first(fn (SatimCall $call): bool => $call->order_status !== null)
            ?->order_status;

        return $status === null ? null : SatimOrderStatus::tryFrom($status);
    }

    /**
     * Calls that were sent but never came back, which are the ones worth
     * chasing: money may have moved without your application hearing about it.
     *
     * @return Collection<int, SatimCall>
     */
    public function unanswered(): Collection
    {
        return $this->calls->filter(fn (SatimCall $call): bool => $call->completed_at === null)->values();
    }

    public function lastCallAt(): ?Carbon
    {
        return $this->calls->last()?->created_at;
    }

    /**
     * @return Collection<int, SatimCall>
     */
    private function successful(SatimOperation $operation): Collection
    {
        return $this->calls
            ->filter(fn (SatimCall $call): bool => $call->operation === $operation && $call->successful === true)
            ->values();
    }

    /**
     * @return Collection<int, SatimCall>
     */
    private function completed(SatimOperation $operation): Collection
    {
        return $this->calls
            ->filter(fn (SatimCall $call): bool => $call->operation === $operation && $call->completed_at !== null)
            ->values();
    }
}
