<?php

declare(strict_types=1);

namespace Taldres\LastSeen;

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
        ], 'config');

        if (! class_exists('AddLastSeenToUsersTable') && $this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../database/migrations/add_last_seen_to_users_table.php.stub' => $this->app->databasePath().DIRECTORY_SEPARATOR.'migrations'.DIRECTORY_SEPARATOR.date('Y_m_d_His').'_add_last_seen_to_users_table.php',
            ], 'migrations');
        }
    }
}
