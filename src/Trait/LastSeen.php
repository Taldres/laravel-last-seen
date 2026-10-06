<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Trait;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Taldres\LastSeen\LastSeenManager;

/**
 * @mixin Model
 *
 * @property Carbon|null $last_seen_at
 */
trait LastSeen
{
    public function initializeLastSeen(): void
    {
        if (! array_key_exists('last_seen_at', $this->casts)) {
            $this->casts['last_seen_at'] = 'datetime';
        }
    }

    /**
     * Writes last_seen_at if tracking is allowed and the update threshold has passed.
     * Returns whether the timestamp was written.
     */
    public function updateLastSeenAt(): bool
    {
        return app(LastSeenManager::class)->record($this);
    }

    /**
     * Sets last_seen_at to null, without touching updated_at or other unsaved attributes.
     */
    public function forgetLastSeenAt(): void
    {
        app(LastSeenManager::class)->forget($this);
    }

    public function recentlySeen(): bool
    {
        return app(LastSeenManager::class)->recentlySeen($this);
    }

    /**
     * @param  Builder<static>  $builder
     */
    public function scopeOnlyRecentlySeen(Builder $builder): void
    {
        $builder->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', app(LastSeenManager::class)->recentlySeenSince());
    }
}
