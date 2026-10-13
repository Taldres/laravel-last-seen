<?php

declare(strict_types=1);

namespace Taldres\LastSeen\Trait;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
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
     * Returns whether the stored timestamp changed.
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
        $column = $builder->qualifyColumn('last_seen_at');
        $since = $this->storableLastSeenAt($builder, app(LastSeenManager::class)->recentlySeenSince());

        $builder->whereNotNull($column)
            ->where($column, '>=', $since);
    }

    /**
     * The exact opposite of onlyRecentlySeen(): users who were not seen recently, including users
     * who were never seen.
     *
     * @param  Builder<static>  $builder
     */
    public function scopeNotRecentlySeen(Builder $builder): void
    {
        $column = $builder->qualifyColumn('last_seen_at');
        $since = $this->storableLastSeenAt($builder, app(LastSeenManager::class)->recentlySeenSince());

        $builder->whereNull($column)
            ->orWhere($column, '<', $since);
    }

    /**
     * @param  Builder<static>  $builder
     */
    public function scopeNeverSeen(Builder $builder): void
    {
        $builder->whereNull($builder->qualifyColumn('last_seen_at'));
    }

    /**
     * Users whose last_seen_at is before the given moment. Users who were never seen are not
     * included, see neverSeen().
     *
     * @param  Builder<static>  $builder
     */
    public function scopeLastSeenBefore(Builder $builder, DateTimeInterface $moment): void
    {
        $column = $builder->qualifyColumn('last_seen_at');

        $builder->whereNotNull($column)
            ->where($column, '<', $this->storableLastSeenAt($builder, $moment));
    }

    /**
     * Users whose last_seen_at is at or after the given moment, for a window other than the recently seen
     * threshold. Users who were never seen are not included. Together with lastSeenBefore() it splits all users
     * who were seen at any moment into two groups without a gap or an overlap.
     *
     * @param  Builder<static>  $builder
     */
    public function scopeLastSeenSince(Builder $builder, DateTimeInterface $moment): void
    {
        $column = $builder->qualifyColumn('last_seen_at');

        $builder->whereNotNull($column)
            ->where($column, '>=', $this->storableLastSeenAt($builder, $moment));
    }

    /**
     * The value as it is stored in the last_seen_at column, in the date format and cast of the model.
     * The moment is converted to the application timezone first, because last_seen_at is written in it.
     *
     * @param  Builder<static>  $builder
     */
    private function storableLastSeenAt(Builder $builder, DateTimeInterface $moment): mixed
    {
        $moment = Date::instance($moment)->setTimezone(date_default_timezone_get());

        return $builder->getModel()->newInstance()
            ->forceFill(['last_seen_at' => $moment])
            ->getAttributes()['last_seen_at'];
    }
}
