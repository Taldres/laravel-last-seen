<?php

declare(strict_types=1);

namespace Taldres\LastSeen;

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
        $this->app->singleton(LastSeenManager::class);

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

    protected function getMigrationFileName(string $migrationFileName): string
    {
        $directory = $this->app->databasePath('migrations');
        $files = is_dir($directory) ? scandir($directory) : false;

        $published = Collection::make($files ?: [])->first(fn (string $file) => preg_match(
            '/^\d{4}_\d{2}_\d{2}_\d{6}_'.preg_quote($migrationFileName, '/').'$/',
            $file,
        ) === 1);

        return $directory.DIRECTORY_SEPARATOR.($published ?? now()->format('Y_m_d_His').'_'.$migrationFileName);
    }
}
