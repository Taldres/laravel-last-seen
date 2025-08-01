<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Trait;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Taldres\LastSeen\Enums\LastSeenDefaultThreshold;

/**
 * @mixin(Model)
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
        if (! config('last-seen.enabled', true)) {
            return;
        }

        $threshold = (int) config('last-seen.update_threshold', LastSeenDefaultThreshold::Update->value);

        if (! $this->last_seen_at || $this->last_seen_at->diffInSeconds(now()) > $threshold) {
            $this->updateQuietly([
                'last_seen_at' => now(),
            ], [
                'timestamps' => false,
            ]);
        }
    }

    public function recentlySeen(): bool
    {
        $threshold = (int) config('last-seen.recently_seen_threshold', LastSeenDefaultThreshold::RecentlySeen->value);

        return $this->last_seen_at && $this->last_seen_at->diffInSeconds(now()) < $threshold;
    }

    #[Scope]
    protected function onlyRecentlySeen(Builder $builder): void
    {
        $threshold = (int) config('last-seen.recently_seen_threshold', LastSeenDefaultThreshold::RecentlySeen->value);

        $builder->whereNotNull('last_seen_at')
            ->where('last_seen_at', '>=', now()->subSeconds($threshold));
    }
}
