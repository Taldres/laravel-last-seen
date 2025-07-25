<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Trait;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin(Model)
 */
trait LastSeen
{
    public function initializeLastSeen(): void
    {
        if (! in_array('last_seen', $this->fillable, true)) {
            $this->fillable[] = 'last_seen';
        }
        if (! array_key_exists('last_seen', $this->casts)) {
            $this->casts['last_seen'] = 'datetime';
        }
    }

    public function updateLastSeen(): void
    {
        if (! config('last-seen.enabled', true)) {
            return;
        }

        $threshold = (int) config('last-seen.update_threshold', 60);

        if (! $this->last_seen || $this->last_seen->diffInSeconds(now()) > $threshold) {
            $this->updateQuietly([
                'last_seen' => now(),
            ], [
                'timestamps' => false,
            ]);
        }
    }

    public function recentlySeen(): bool
    {
        $threshold = (int) config('last-seen.recently_seen_threshold', 300);

        return $this->last_seen && $this->last_seen->diffInSeconds(now()) < $threshold;
    }

    #[Scope]
    protected function onlyRecentlySeen(Builder $builder): void
    {
        $threshold = (int) config('last-seen.recently_seen_threshold', 300);

        $builder->whereNotNull('last_seen')
            ->where('last_seen', '>=', now()->subSeconds($threshold));
    }
}
