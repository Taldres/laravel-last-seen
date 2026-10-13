<?php

declare(strict_types=1);

namespace Taldres\LastSeen;

use Carbon\CarbonInterface;
use Closure;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Event;
use InvalidArgumentException;
use LogicException;
use Taldres\LastSeen\Enums\LastSeenDefaultThreshold;
use Taldres\LastSeen\Events\LastSeenRecordedEvent;
use Taldres\LastSeen\Trait\LastSeen;

class LastSeenManager
{
    /**
     * @var (Closure(Model): bool)|null
     */
    private ?Closure $trackUsing = null;

    /**
     * @var (Closure(Request): bool)|null
     */
    private ?Closure $ignoreRequestsUsing = null;

    /**
     * Writes last_seen_at if tracking is allowed and the update threshold has passed.
     * Returns whether the stored timestamp changed, in which case a LastSeenRecordedEvent is fired.
     */
    public function record(Model $user): bool
    {
        if (! $user->exists || ! config('last-seen.enabled', true)) {
            return false;
        }

        $threshold = $this->threshold('last-seen.update_threshold', LastSeenDefaultThreshold::Update);

        $loaded = Arr::exists($user->getAttributes(), 'last_seen_at');
        $lastSeenAt = $loaded ? $user->getAttribute('last_seen_at') : null;
        // A model that was just created has not been seen, although it does not hold the column yet.
        $known = $loaded || $user->wasRecentlyCreated;

        if ($lastSeenAt instanceof CarbonInterface && ! $lastSeenAt->isFuture() && $lastSeenAt->diffInSeconds(now()) < $threshold) {
            return false;
        }

        if (! $this->shouldTrack($user)) {
            return false;
        }

        // An invalid recently seen threshold has to fail before the row is written.
        [$previous, $becameRecentlySeen] = $this->previousActivity($known, $lastSeenAt, $this->recentlySeenSince());

        $timestamp = $user->freshTimestamp();
        $now = $this->storable($user, $timestamp);
        $outdated = $this->storable($user, $timestamp->copy()->subSeconds($threshold));
        // Only the future value this model read is replaced. A newer value that a parallel request wrote in the
        // meantime is also later than this request's timestamp, and must stay.
        $future = $lastSeenAt instanceof CarbonInterface && $lastSeenAt->isFuture()
            ? ($user->isDirty('last_seen_at') ? null : $user->getRawOriginal('last_seen_at')) ?? $this->storable($user, $lastSeenAt)
            : null;

        // Write only last_seen_at through the base query builder, so updated_at, model events
        // and other unsaved attributes stay untouched. The threshold is checked again in the
        // query, so parallel requests write only once. With a threshold of 0, a row that
        // already holds the current timestamp is left alone.
        $written = $user->newModelQuery()
            ->whereKey($this->originalKey($user))
            ->toBase()
            ->where(fn (QueryBuilder $query) => $query
                ->whereNull('last_seen_at')
                ->orWhere('last_seen_at', $threshold > 0 ? '<=' : '<', $outdated)
                ->when($future !== null, fn (QueryBuilder $query) => $query->orWhere('last_seen_at', $future)))
            ->update(['last_seen_at' => $now]) > 0;

        if ($written) {
            $user->forceFill(['last_seen_at' => $timestamp])->syncOriginalAttribute('last_seen_at');

            Event::dispatch(new LastSeenRecordedEvent($user, $previous, $becameRecentlySeen, $this->recordedAt($user, $now, $timestamp)));
        }

        return $written;
    }

    /**
     * The written value as a freshly loaded model reads it, so it has the precision of the stored value. The model
     * itself can still hold the unformatted timestamp, because Eloquent caches the object a class cast was given.
     */
    private function recordedAt(Model $user, mixed $stored, CarbonInterface $timestamp): CarbonInterface
    {
        $value = $user->newInstance()->setRawAttributes(['last_seen_at' => $stored])->getAttribute('last_seen_at');

        return match (true) {
            $value instanceof CarbonInterface => $value,
            $value instanceof DateTimeInterface => Date::instance($value),
            default => $timestamp->copy(),
        };
    }

    /**
     * The value the model held and whether the user was not recently seen before. A model loaded without last_seen_at has an unknown
     * value: it claims no transition it did not observe.
     *
     * @return array{0: CarbonInterface|null, 1: bool}
     */
    private function previousActivity(bool $known, mixed $lastSeenAt, CarbonInterface $since): array
    {
        if ($lastSeenAt instanceof CarbonInterface) {
            return [$lastSeenAt->copy(), ! $lastSeenAt->gte($since)];
        }

        return [null, $known && $lastSeenAt === null];
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

        $key = $this->originalKey($user);

        if ($key === null) {
            throw new LogicException('Cannot forget last_seen_at of a model that was loaded without its primary key.');
        }

        $user->newModelQuery()->whereKey($key)->toBase()->update(['last_seen_at' => null]);

        $user->forceFill(['last_seen_at' => null])->syncOriginalAttribute('last_seen_at');
    }

    private function storable(Model $user, CarbonInterface $value): mixed
    {
        return $user->newInstance()->forceFill(['last_seen_at' => $value])->getAttributes()['last_seen_at'];
    }

    private function originalKey(Model $user): mixed
    {
        return $user->getRawOriginal($user->getKeyName()) ?? $user->getKey();
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
        $threshold = $this->threshold('last-seen.recently_seen_threshold', LastSeenDefaultThreshold::RecentlySeen);

        return now()->subSeconds($threshold)->startOfSecond();
    }

    private function threshold(string $key, LastSeenDefaultThreshold $default): int
    {
        $threshold = config()->integer($key, $default->value);

        if ($threshold < 0) {
            throw new InvalidArgumentException("Configuration value for key [{$key}] must be at least 0, {$threshold} given.");
        }

        return $threshold;
    }

    /**
     * Determines whether last_seen_at may be written for the user: the package is enabled, the
     * model uses the LastSeen trait and the callback registered with trackUsing() allows it.
     */
    public function shouldTrack(Model $user): bool
    {
        return config('last-seen.enabled', true)
            && in_array(LastSeen::class, class_uses_recursive($user), true)
            && ($this->trackUsing === null || ($this->trackUsing)($user));
    }

    /**
     * Registers a callback that decides per user whether last_seen_at may be written.
     *
     * @param  (Closure(Model): bool)|null  $callback
     */
    public function trackUsing(?Closure $callback): void
    {
        $this->trackUsing = $callback;
    }

    /**
     * Determines whether the request is left out as activity: the callback registered with
     * ignoreRequestsUsing() returned true for it.
     */
    public function shouldIgnoreRequest(Request $request): bool
    {
        return $this->ignoreRequestsUsing !== null && ($this->ignoreRequestsUsing)($request);
    }

    /**
     * Registers a callback that decides per request whether it is left out as activity, for example
     * a request that polls in the background. It runs for every request that passes the middleware,
     * guests included, before the user is resolved.
     *
     * @param  (Closure(Request): bool)|null  $callback
     */
    public function ignoreRequestsUsing(?Closure $callback): void
    {
        $this->ignoreRequestsUsing = $callback;
    }
}
