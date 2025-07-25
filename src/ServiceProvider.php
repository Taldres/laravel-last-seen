<?php

declare(strict_types=1);

namespace Taldres\LastSeen;

use Illuminate\Support\Facades\Event;
use Taldres\LastSeen\Listeners\LastSeenSubscriber;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__.'/../config/last-seen.php' => config_path('last-seen.php'),
        ], 'config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Event::subscribe(LastSeenSubscriber::class);
    }

    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/last-seen.php', 'last-seen'
        );

        $config = config('last-seen');

        if (!isset($config['user_model']) || !class_exists($config['user_model'])) {
            throw new \RuntimeException("Configured user_model does not exist: " . ($config['user_model'] ?? 'null'));
        }

        if (!isset($config['update_threshold']) || !is_int($config['update_threshold'])) {
            throw new \RuntimeException("Configured update_threshold must be an integer.");
        }

        if (!isset($config['online_indicator']) || !is_int($config['online_indicator'])) {
            throw new \RuntimeException("Configured online_indicator must be an integer.");
        }
    }
}
