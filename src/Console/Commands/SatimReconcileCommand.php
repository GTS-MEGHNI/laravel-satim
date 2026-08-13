<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Console\Commands;

use GtsMeghni\Satim\Enums\SatimOperation;
use GtsMeghni\Satim\Events\SatimOrderReconciled;
use GtsMeghni\Satim\Exceptions\SatimConnectionException;
use GtsMeghni\Satim\Exceptions\SatimValidationException;
use GtsMeghni\Satim\Models\SatimCall;
use GtsMeghni\Satim\Satim;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

/**
 * Confirms orders whose customer never came back.
 *
 * SATIM redirects the customer to the return URL, and that is where an
 * application normally calls acknowledge. A closed tab, a dead battery, or a
 * dropped connection means the redirect never happens, so the order stays
 * unconfirmed even though the card may well have been charged.
 *
 * This asks the gateway what actually happened and announces the answer. It
 * updates nothing of yours: listen for SatimOrderReconciled and settle the
 * order in your own records.
 *
 * Schedule it, for example every fifteen minutes:
 *
 *     Schedule::command('satim:reconcile')->everyFifteenMinutes()->withoutOverlapping();
 */
class SatimReconcileCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'satim:reconcile
        {--after= : Minutes an order must have gone unconfirmed before it is asked about}
        {--within= : Ignore orders registered longer ago than this many minutes}
        {--limit= : Most orders to confirm in this run}
        {--dry-run : List the orders that would be confirmed without calling the gateway}';

    /**
     * The command description.
     */
    protected $description = 'Confirm SATIM orders whose customer never returned from the payment page.';

    /**
     * Execute the console command.
     */
    public function handle(Satim $satim): int
    {
        if (! Config::boolean('satim.audit.enabled', true)) {
            $this->components->error(
                'The audit trail is switched off, so there is no record of unconfirmed orders to work from.',
            );

            return self::FAILURE;
        }

        $orders = $this->unconfirmed();

        if ($orders->isEmpty()) {
            $this->components->info('No unconfirmed orders.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run') === true) {
            foreach ($orders as $order) {
                $this->components->twoColumnDetail((string) $order->order_id, $order->created_at->diffForHumans());
            }

            $this->components->info($orders->count().' '.Str::plural('order', $orders->count()).' would be confirmed.');

            return self::SUCCESS;
        }

        return $this->confirmOrders($satim, $orders);
    }

    /**
     * Ask the gateway about each order and announce what it said.
     *
     * A gateway that cannot be reached is left for the next run rather than
     * failing the command: the order is still unconfirmed, and a scheduled
     * command that fails on a passing network fault is noise.
     *
     * @param  Collection<int, SatimCall>  $orders
     */
    private function confirmOrders(Satim $satim, Collection $orders): int
    {
        $unreachable = 0;

        foreach ($orders as $order) {
            $orderId = (string) $order->order_id;

            try {
                $result = $satim->acknowledge($orderId);
            } catch (SatimConnectionException|SatimValidationException $e) {
                $unreachable++;
                $this->components->twoColumnDetail($orderId, '<fg=yellow>could not be reached</>');
                $this->components->warn($e->getMessage());

                continue;
            }

            Event::dispatch(new SatimOrderReconciled($orderId, $result));

            $this->components->twoColumnDetail($orderId, match (true) {
                $result->paid() => '<fg=green>paid</>',
                $result->status !== null => $result->status->name,
                default => 'unknown',
            });
        }

        $confirmed = $orders->count() - $unreachable;

        $this->components->info("$confirmed of {$orders->count()} ".Str::plural('order', $orders->count()).' confirmed.');

        return self::SUCCESS;
    }

    /**
     * Orders SATIM registered and nothing ever answered for.
     *
     * An acknowledge call that completed is an answer, whatever it said, so
     * only orders with none at all are picked up. A call that never came back
     * did not complete, which is exactly the one worth trying again.
     *
     * @return Collection<int, SatimCall>
     */
    private function unconfirmed(): Collection
    {
        $table = (new SatimCall)->getTable();

        return SatimCall::query()
            ->operation(SatimOperation::Register)
            ->successful()
            ->whereNotNull('order_id')
            ->where('created_at', '<=', Date::now()->subMinutes($this->numeric('after', 30)))
            ->where('created_at', '>=', Date::now()->subMinutes($this->numeric('within', 10080)))
            ->whereNotExists(fn (QueryBuilder $query): QueryBuilder => $query
                ->from($table, 'answered')
                ->whereColumn('answered.order_id', "$table.order_id")
                ->where('answered.operation', SatimOperation::Acknowledge->value)
                ->whereNotNull('answered.completed_at'))
            ->orderBy('id')
            ->limit($this->numeric('limit', 100))
            ->get();
    }

    /**
     * A whole number from the option, the config, or the shipped default.
     */
    private function numeric(string $option, int $fallback): int
    {
        $value = $this->option($option);

        if ((is_string($value) || is_int($value)) && is_numeric($value)) {
            return (int) $value;
        }

        return Config::integer("satim.reconcile.$option", $fallback);
    }
}
