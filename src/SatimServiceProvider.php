<?php

declare(strict_types=1);

namespace GtsMeghni\Satim;

use GtsMeghni\Satim\Console\Commands\SatimCheckCommand;
use GtsMeghni\Satim\Console\Commands\SatimReconcileCommand;
use GtsMeghni\Satim\Contracts\SatimTransport;
use GtsMeghni\Satim\Data\SatimConfig;
use GtsMeghni\Satim\Events\SatimCallCompleted;
use GtsMeghni\Satim\Events\SatimCallFailed;
use GtsMeghni\Satim\Events\SatimCallStarted;
use GtsMeghni\Satim\Http\SatimClient;
use GtsMeghni\Satim\Listeners\SatimCallRecorder;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class SatimServiceProvider extends ServiceProvider
{
    /**
     * Whether the package runs its own audit migration.
     *
     * An application that publishes the migration owns the schema from then on,
     * and calls ignoreMigrations() so the table is not created twice.
     */
    private static bool $runsMigrations = true;

    /**
     * Stop the package from running its own audit migration.
     *
     * Call this from a service provider's register() method after publishing
     * the migration with the `satim-migrations` tag.
     */
    public static function ignoreMigrations(): void
    {
        self::$runsMigrations = false;
    }

    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/satim.php', 'satim');

        // Resolved lazily: an unconfigured application still boots, and the
        // first gateway call fails with the offending config key instead.
        $this->app->singleton(
            SatimConfig::class,
            fn (): SatimConfig => SatimConfig::fromArray(Config::array('satim')),
        );

        // Bound to the interface so a test can put SatimFake in its place and
        // still exercise everything the real service does above the transport.
        $this->app->singleton(SatimTransport::class, SatimClient::class);

        $this->app->singleton(Satim::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'satim');

        // The audit trail is on by default, so the table has to exist without
        // anything being published first.
        if (self::$runsMigrations) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        // Always listening, but the recorder itself checks whether recording is
        // switched on. Deciding here instead would freeze the answer at boot and
        // ignore any later change to the setting.
        Event::listen(SatimCallStarted::class, [SatimCallRecorder::class, 'started']);
        Event::listen(SatimCallCompleted::class, [SatimCallRecorder::class, 'completed']);
        Event::listen(SatimCallFailed::class, [SatimCallRecorder::class, 'failed']);

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/satim.php' => config_path('satim.php'),
        ], ['satim', 'satim-config']);

        $this->publishes([
            __DIR__.'/../lang' => $this->app->langPath('vendor/satim'),
        ], ['satim', 'satim-lang']);

        $this->publishes([
            __DIR__.'/../public' => public_path('vendor/satim'),
        ], ['satim', 'satim-assets']);

        // Publishing is only for an application that wants to own the schema,
        // and it pairs with ignoreMigrations(). Deliberately left out of the
        // `satim` tag: publishing everything must not duplicate the table.
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], ['satim-migrations']);

        $this->commands([
            SatimCheckCommand::class,
            SatimReconcileCommand::class,
        ]);
    }
}
