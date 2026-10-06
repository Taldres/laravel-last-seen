<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Facades;

use Illuminate\Support\Facades\Facade;
use Taldres\LastSeen\LastSeenManager;

/**
 * @method static bool record(\Illuminate\Database\Eloquent\Model $user)
 * @method static void forget(\Illuminate\Database\Eloquent\Model $user)
 * @method static bool recentlySeen(\Illuminate\Database\Eloquent\Model $user)
 * @method static \Carbon\CarbonInterface recentlySeenSince()
 * @method static bool shouldTrack(\Illuminate\Database\Eloquent\Model $user)
 * @method static void trackUsing((\Closure(\Illuminate\Database\Eloquent\Model): bool)|null $callback)
 *
 * @see LastSeenManager
 */
class LastSeen extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return LastSeenManager::class;
    }
}
