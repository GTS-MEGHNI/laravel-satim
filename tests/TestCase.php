<?php

declare(strict_types=1);

namespace GtsMeghni\Satim\Tests;

use GtsMeghni\Satim\SatimServiceProvider;
use Illuminate\Foundation\Application;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * A complete, valid gateway configuration.
     *
     * @return array<string, mixed>
     */
    public static function validConfig(): array
    {
        return [
            'base_url' => 'https://test2.satim.dz/payment/rest',
            'username' => 'merchant-user',
            'password' => 'merchant-secret',
            'terminal_id' => 'E0123456789',
            'currency' => '012',
            'language' => 'FR',
            'timeout' => 30,
            'connect_timeout' => 10,
            'return_url' => 'https://merchant.test/satim/return',
            'fail_url' => 'https://merchant.test/satim/fail',
            'receipt' => [
                'logo' => '/vendor/satim/satim.png',
            ],
            'audit' => [
                'enabled' => true,
                'table' => 'satim_calls',
            ],
            'reconcile' => [
                'after' => 30,
                'within' => 60 * 24 * 7,
                'limit' => 100,
            ],
        ];
    }

    protected function getPackageProviders($app): array
    {
        return [
            SatimServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        /** @var Application $app */
        $app['config']->set('satim', self::validConfig());

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', self::databaseConnection());
    }

    /**
     * In-memory SQLite by default, so the suite needs nothing installed.
     *
     * Set DB_DRIVER to mysql, pgsql, or sqlsrv to run the same tests against a
     * real server: SQLite accepts schemas the others reject, so the migration is
     * only proven portable by running it on each.
     *
     * @return array<string, mixed>
     */
    protected static function databaseConnection(): array
    {
        $driver = getenv('DB_DRIVER') ?: 'sqlite';

        if ($driver === 'sqlite') {
            return ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''];
        }

        return [
            'driver' => $driver,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: match ($driver) {
                'pgsql' => '5432',
                'sqlsrv' => '1433',
                default => '3306',
            },
            'database' => getenv('DB_DATABASE') ?: 'satim_check',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') ?: 'root',
            'prefix' => '',
            'charset' => $driver === 'sqlsrv' ? null : 'utf8',
            'trust_server_certificate' => true,
        ];
    }
}
