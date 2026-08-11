<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Console\Commands;

use GtsMeghni\Satim\Data\SatimConfig;
use GtsMeghni\Satim\Exceptions\SatimConfigurationException;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;

class SatimCheckCommand extends Command
{
    /**
     * The command signature.
     */
    protected $signature = 'satim:check';

    /**
     * The command description.
     */
    protected $description = 'Validate the configured SATIM gateway credentials and endpoint.';

    /**
     * Execute the console command.
     *
     * Run this on deploy: it turns a missing environment variable into a failed
     * release instead of a failed payment at checkout.
     */
    public function handle(): int
    {
        try {
            // Built straight from config rather than resolved from the
            // container, so the command always reports the current values and
            // never a previously cached instance.
            $config = SatimConfig::fromArray(Config::array('satim'));
        } catch (SatimConfigurationException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->table(['Setting', 'Value'], [
            ['base_url', $config->baseUrl],
            ['username', $config->username],
            ['password', str_repeat('*', 8)],
            ['terminal_id', $config->terminalId],
            ['return_url', $config->returnUrl],
            ['fail_url', $config->failUrl],
            ['currency', $config->currency],
            ['language', $config->language->value],
            ['timeout', $config->timeout.'s'],
            ['connect_timeout', $config->connectTimeout.'s'],
            ['receipt.logo', $config->receiptLogo ?? 'not set (receipts unavailable)'],
        ]);

        $this->components->info('The SATIM configuration is valid.');

        return self::SUCCESS;
    }
}
