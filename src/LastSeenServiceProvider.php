<?php

declare(strict_types=1);

namespace Taldres\LastSeen;

use Composer\InstalledVersions;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Taldres\LastSeen\Listeners\LastSeenSubscriber;
use Taldres\LastSeen\Trait\LastSeen;
use Throwable;

class LastSeenServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->providePublishing();
        $this->provideAboutSection();

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

    /**
     * Adds a "Last Seen" section to `php artisan about` that shows the settings the package runs with, and marks
     * a user model without the trait and thresholds that cannot work.
     */
    private function provideAboutSection(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        AboutCommand::add('Last Seen', function (): array {
            $model = config('last-seen.models.user');

            try {
                $usesTrait = is_string($model) && class_exists($model)
                    ? in_array(LastSeen::class, class_uses_recursive($model), true)
                    : null;
            } catch (Throwable) {
                // A model class that fails to load must not take down the whole about command.
                $usesTrait = null;
            }

            $enabled = (bool) config('last-seen.enabled', true);
            $update = config('last-seen.update_threshold');
            $recentlySeen = config('last-seen.recently_seen_threshold');
            $seconds = fn (mixed $threshold): ?int => is_int($threshold) && $threshold >= 0 ? $threshold : null;
            $updateSeconds = $seconds($update);
            $recentlySeenSeconds = $seconds($recentlySeen);

            return [
                'Version' => InstalledVersions::isInstalled('taldres/laravel-last-seen')
                    ? InstalledVersions::getPrettyVersion('taldres/laravel-last-seen')
                    : null,
                'Enabled' => AboutCommand::format($enabled, console: fn (): string => $enabled
                    ? '<fg=green;options=bold>ENABLED</>'
                    : '<fg=yellow;options=bold>OFF</>'),
                'User Model' => AboutCommand::format($model, console: fn (): string => is_string($model)
                    ? $model
                    : '<fg=red;options=bold>NOT A CLASS NAME</>'),
                'User Model Trait' => AboutCommand::format($usesTrait, console: fn (): string => match ($usesTrait) {
                    true => '<fg=green;options=bold>IN USE</>',
                    false => '<fg=yellow;options=bold>MISSING</>',
                    null => '<fg=red;options=bold>MODEL NOT FOUND</>',
                }),
                'Update Threshold' => AboutCommand::format($update, console: fn (): string => $updateSeconds === null
                    ? '<fg=red;options=bold>INVALID</>'
                    : "{$updateSeconds} seconds"),
                'Recently Seen Threshold' => AboutCommand::format($recentlySeen, console: fn (): string => match (true) {
                    $recentlySeenSeconds === null => '<fg=red;options=bold>INVALID</>',
                    $updateSeconds !== null && $recentlySeenSeconds <= $updateSeconds => "<fg=yellow;options=bold>{$recentlySeenSeconds} seconds, not above the update threshold</>",
                    default => "{$recentlySeenSeconds} seconds",
                }),
            ];
        });
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
