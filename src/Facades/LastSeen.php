<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Facades;

use Illuminate\Support\Facades\Facade;
use Taldres\LastSeen\LastSeenManager;
use Taldres\LastSeen\Testing\LastSeenFake;

/**
 * @method static bool record(\Illuminate\Database\Eloquent\Model $user)
 * @method static void forget(\Illuminate\Database\Eloquent\Model $user)
 * @method static bool recentlySeen(\Illuminate\Database\Eloquent\Model $user)
 * @method static \Carbon\CarbonInterface recentlySeenSince()
 * @method static bool shouldTrack(\Illuminate\Database\Eloquent\Model $user)
 * @method static void trackUsing(\Closure|null $callback)
 * @method static void assertRecorded(\Illuminate\Database\Eloquent\Model|\Closure $user)
 * @method static void assertRecordedTimes(\Illuminate\Database\Eloquent\Model|\Closure $user, int $times = 1)
 * @method static void assertNotRecorded(\Illuminate\Database\Eloquent\Model|\Closure $user)
 * @method static void assertNothingRecorded()
 * @method static void assertForgotten(\Illuminate\Database\Eloquent\Model|\Closure $user)
 * @method static void assertNotForgotten(\Illuminate\Database\Eloquent\Model|\Closure $user)
 * @method static void assertNothingForgotten()
 * @method static \Illuminate\Support\Collection<int,\Illuminate\Database\Eloquent\Model> recorded(\Illuminate\Database\Eloquent\Model|\Closure|null $user = null)
 * @method static \Illuminate\Support\Collection<int,\Illuminate\Database\Eloquent\Model> forgotten(\Illuminate\Database\Eloquent\Model|\Closure|null $user = null)
 *
 * @see LastSeenManager
 * @see LastSeenFake
 */
class LastSeen extends Facade
{
    /**
     * Replaces the manager with a fake that writes nothing and records which users were recorded
     * or forgotten. The configuration and the trackUsing() callback still apply.
     */
    public static function fake(): LastSeenFake
    {
        $fake = new LastSeenFake(app(LastSeenManager::class));

        static::swap($fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return LastSeenManager::class;
    }
}
