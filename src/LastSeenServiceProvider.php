<?php

declare(strict_types=1);

namespace Taldres\LastSeen;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Taldres\LastSeen\Listeners\LastSeenSubscriber;

class LastSeenServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->providePublishing();

        Event::subscribe(LastSeenSubscriber::class);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/last-seen.php',
            'last-seen'
        );
    }

    private function providePublishing(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        if (! function_exists('config_path')) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/last-seen.php' => config_path('last-seen.php'),
        ], [
            'last-seen-config',
            'config',
        ]);

        $this->publishes([
            __DIR__.'/../database/migrations/add_last_seen_at_to_users_table.php.stub' => $this->getMigrationFileName('add_last_seen_at_to_users_table.php'),
        ], [
            'last-seen-migrations',
            'migrations',
        ]);
    }

    /**
     * Returns existing migration file if found, else uses the current timestamp.
     * Thanks to Spatie for this function.
     */
    protected function getMigrationFileName(string $migrationFileName): string
    {
        $timestamp = date('Y_m_d_His');

        $filesystem = $this->app->make(Filesystem::class);

        return Collection::make([$this->app->databasePath().DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR])
            ->flatMap(fn ($path) => $filesystem->glob($path.'*_'.$migrationFileName))
            ->push($this->app->databasePath()."/migrations/{$timestamp}_{$migrationFileName}")
            ->first();
    }
}
