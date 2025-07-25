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

    public function isOnline(): bool
    {
        $indicator = (int) config('last-seen.online_indicator', 300);

        return $this->last_seen && $this->last_seen->diffInSeconds(now()) < $indicator;
    }

    #[Scope]
    protected function onlyOnline(Builder $builder): void
    {
        $indicator = (int) config('last-seen.online_indicator', 300);

        $builder->whereNotNull('last_seen')
            ->where('last_seen', '>=', now()->subSeconds($indicator));
    }
}
