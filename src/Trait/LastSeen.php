<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Trait;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Taldres\LastSeen\Enums\LastSeenDefaultThreshold;

/**
 * @mixin Model
 *
 * @property Carbon|null $last_seen_at
 */
trait LastSeen
{
    public function initializeLastSeen(): void
    {
        if (! in_array('last_seen_at', $this->fillable, true)) {
            $this->fillable[] = 'last_seen_at';
        }
        if (! array_key_exists('last_seen_at', $this->casts)) {
            $this->casts['last_seen_at'] = 'datetime';
        }
    }

    public function updateLastSeenAt(): void
    {
        if (! $this->exists || ! config('last-seen.enabled', true)) {
            return;
        }

        $threshold = (int) config('last-seen.update_threshold', LastSeenDefaultThreshold::Update->value);

        if (! $this->last_seen_at || $this->last_seen_at->diffInSeconds(now()) > $threshold) {
            $timestamp = $this->freshTimestamp();

            // Write only last_seen_at through the base query builder, so updated_at, model events
            // and other unsaved attributes stay untouched, then sync the in-memory model.
            $this->newModelQuery()
                ->whereKey($this->getKey())
                ->toBase()
                ->update(['last_seen_at' => $this->fromDateTime($timestamp)]);

            $this->forceFill(['last_seen_at' => $timestamp])->syncOriginalAttribute('last_seen_at');
        }
    }

    public function recentlySeen(): bool
    {
        $threshold = (int) config('last-seen.recently_seen_threshold', LastSeenDefaultThreshold::RecentlySeen->value);

        return $this->last_seen_at && $this->last_seen_at->diffInSeconds(now()) < $threshold;
    }

    public function scopeOnlyRecentlySeen(Builder $builder): void
    {
        $threshold = (int) config('last-seen.recently_seen_threshold', LastSeenDefaultThreshold::RecentlySeen->value);

        $builder->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subSeconds($threshold));
    }
}
