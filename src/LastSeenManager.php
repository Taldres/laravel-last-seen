<?php

declare(strict_types=1);

namespace Taldres\LastSeen;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Taldres\LastSeen\Enums\LastSeenDefaultThreshold;
use Taldres\LastSeen\Trait\LastSeen;

class LastSeenManager
{
    /**
     * Writes last_seen_at if tracking is allowed and the update threshold has passed.
     * Returns whether the timestamp was written.
     */
    public function record(Model $user): bool
    {
        if (! $user->exists || ! $this->shouldTrack($user)) {
            return false;
        }

        $threshold = config()->integer('last-seen.update_threshold', LastSeenDefaultThreshold::Update->value);
        $lastSeenAt = $user->getAttribute('last_seen_at');

        if ($lastSeenAt instanceof CarbonInterface && $lastSeenAt->diffInSeconds(now()) <= $threshold) {
            return false;
        }

        $timestamp = $user->freshTimestamp();
        $outdated = $user->fromDateTime($timestamp->copy()->subSeconds($threshold));

        // Write only last_seen_at through the base query builder, so updated_at, model events
        // and other unsaved attributes stay untouched. The threshold is checked again in the
        // query, so parallel requests write only once.
        $written = $user->newModelQuery()
            ->whereKey($user->getKey())
            ->toBase()
            ->where(fn (QueryBuilder $query) => $query
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', '<=', $outdated))
            ->update(['last_seen_at' => $user->fromDateTime($timestamp)]) > 0;

        if ($written) {
            $user->forceFill(['last_seen_at' => $timestamp])->syncOriginalAttribute('last_seen_at');
        }

        return $written;
    }

    /**
     * Sets last_seen_at to null, without touching updated_at or other unsaved attributes.
     * It works even when the package is disabled.
     */
    public function forget(Model $user): void
    {
        if (! $user->exists) {
            return;
        }

        $user->newModelQuery()->whereKey($user->getKey())->toBase()->update(['last_seen_at' => null]);

        $user->forceFill(['last_seen_at' => null])->syncOriginalAttribute('last_seen_at');
    }

    public function recentlySeen(Model $user): bool
    {
        $lastSeenAt = $user->getAttribute('last_seen_at');

        return $lastSeenAt instanceof CarbonInterface && $lastSeenAt->gte($this->recentlySeenSince());
    }

    /**
     * The earliest last_seen_at that still counts as recently seen. It is cut to whole seconds
     * like the stored timestamp.
     */
    public function recentlySeenSince(): CarbonInterface
    {
        $threshold = config()->integer('last-seen.recently_seen_threshold', LastSeenDefaultThreshold::RecentlySeen->value);

        return now()->subSeconds($threshold)->startOfSecond();
    }

    /**
     * Determines whether last_seen_at may be written for the user: the package is enabled and
     * the model uses the LastSeen trait.
     */
    public function shouldTrack(Model $user): bool
    {
        return config('last-seen.enabled', true)
            && in_array(LastSeen::class, class_uses_recursive($user), true);
    }
}
