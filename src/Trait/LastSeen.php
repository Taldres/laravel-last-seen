<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Trait;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
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
        if (! array_key_exists('last_seen_at', $this->casts)) {
            $this->casts['last_seen_at'] = 'datetime';
        }
    }

    public function updateLastSeenAt(): void
    {
        if (! $this->exists || ! config('last-seen.enabled', true)) {
            return;
        }

        $threshold = config()->integer('last-seen.update_threshold', LastSeenDefaultThreshold::Update->value);

        if (! $this->last_seen_at || $this->last_seen_at->diffInSeconds(now()) > $threshold) {
            $timestamp = $this->freshTimestamp();
            $outdated = $this->fromDateTime($timestamp->copy()->subSeconds($threshold));

            // Write only last_seen_at through the base query builder, so updated_at, model events
            // and other unsaved attributes stay untouched. The threshold is checked again in the
            // query, so parallel requests write only once.
            $written = $this->newModelQuery()
                ->whereKey($this->getKey())
                ->toBase()
                ->where(fn (QueryBuilder $query) => $query
                    ->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<=', $outdated))
                ->update(['last_seen_at' => $this->fromDateTime($timestamp)]) > 0;

            if ($written) {
                $this->forceFill(['last_seen_at' => $timestamp])->syncOriginalAttribute('last_seen_at');
            }
        }
    }

    public function recentlySeen(): bool
    {
        return $this->last_seen_at !== null && $this->last_seen_at->gte($this->recentlySeenSince());
    }

    public function scopeOnlyRecentlySeen(Builder $builder): void
    {
        $builder->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', $this->recentlySeenSince());
    }

    /**
     * The earliest last_seen_at that still counts as recently seen, shared by recentlySeen()
     * and scopeOnlyRecentlySeen(). It is cut to whole seconds like the stored timestamp.
     */
    private function recentlySeenSince(): CarbonInterface
    {
        $threshold = config()->integer('last-seen.recently_seen_threshold', LastSeenDefaultThreshold::RecentlySeen->value);

        return now()->subSeconds($threshold)->startOfSecond();
    }
}
