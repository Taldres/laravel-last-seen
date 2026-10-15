---
name: laravel-last-seen-development
description: Track and query when users were last active with taldres/laravel-last-seen, including the last_seen_at column, recentlySeen(), ignoring background requests, the onlyRecentlySeen(), notRecentlySeen(), neverSeen(), lastSeenBefore() and lastSeenSince() scopes, UpdateLastSeenMiddleware, the UserWasActiveEvent and LastSeenRecordedEvent events, the LastSeen facade, faking it in tests and opting users out.
---

# Laravel Last Seen Development

## When to use this skill

Use this skill when an application needs to know when users were last active, show who was recently active, or let users
hide their activity, and `taldres/laravel-last-seen` is installed. The package records the time of the last request. It
does not know whether a user is present right now: use broadcasting presence channels for that. To upgrade from 0.x, use
the `upgrade-laravel-last-seen-v1` skill instead.

## Setup

1. Publish the config and migration, then migrate. The migration adds a nullable, indexed `last_seen_at` column to
   the users table and stops with an error if the column already exists:

    ```bash
    php artisan vendor:publish --provider="Taldres\LastSeen\LastSeenServiceProvider"
    php artisan migrate
    ```

2. Add the trait to the user model:

    ```php
    use Taldres\LastSeen\Trait\LastSeen;

    class User extends Authenticatable
    {
        use LastSeen;
    }
    ```

3. Register the middleware in `bootstrap/app.php`. It resolves the user after the request, so it also works when
   authentication runs later, for example with a route-level `auth:sanctum`:

    ```php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \Taldres\LastSeen\Middleware\UpdateLastSeenMiddleware::class,
        ]);
    })
    ```

Settings live in `config/last-seen.php` and the `.env` file: `LAST_SEEN_ENABLED`, `LAST_SEEN_UPDATE_THRESHOLD`
(seconds between writes, default 60) and `LAST_SEEN_RECENTLY_SEEN_THRESHOLD` (seconds a user counts as recently
seen, default 300). Both thresholds must be integers of 0 or more, otherwise they throw an `InvalidArgumentException`;
an invalid `.env` value is rejected, not cast to 0, and an empty one falls back to the default. A configuration file
published before 1.6.1 still casts with `(int)`: replace the casts with plain `env('LAST_SEEN_UPDATE_THRESHOLD', 60)` and
`env('LAST_SEEN_RECENTLY_SEEN_THRESHOLD', 300)` calls as in the current config file. The package turns digit strings
from the `.env` file into integers itself.
`php artisan about --only=last_seen` (add `--json` to parse it) shows these settings and whether the user model uses the
trait (only the class in `models.user`), and marks a missing trait, invalid thresholds and a recently seen threshold
that is not above the update threshold. Run it to check the setup.

## Reading Activity

```php
$user->last_seen_at;                                // Carbon instance or null
$user->recentlySeen();                              // seen within the recently seen threshold
User::onlyRecentlySeen()->get();                    // same rule as a query scope, also inside joins
User::notRecentlySeen()->get();                     // the exact opposite, including users who were never seen
User::neverSeen()->get();                           // last_seen_at is null
User::lastSeenBefore(now()->subDays(30))->get();    // last seen before a moment, without users who were never seen
User::lastSeenSince(now()->subMinutes(15))->get();  // last seen at or after a moment, for a window of your own
```

To find everyone inactive for a while, including users who were never seen, combine the scopes:
`$query->neverSeen()->orWhere->lastSeenBefore(now()->subDays(30))` inside a `where(fn ($query) => ...)` group.
`lastSeenSince()` and `lastSeenBefore()` for the same moment split the users who were seen without a gap or an overlap.
Windows shorter than the update threshold are not reliable, because `last_seen_at` lags by up to that many seconds.

## Writing and Deleting Activity

The middleware records activity automatically. Record it manually only outside HTTP requests, for example in a job:

```php
use Taldres\LastSeen\Facades\LastSeen;

LastSeen::record($user);    // same as $user->updateLastSeenAt(), returns whether the stored timestamp changed
LastSeen::forget($user);    // same as $user->forgetLastSeenAt(), sets last_seen_at to null
```

Writes only change `last_seen_at`: they do not touch `updated_at`, fire no model events and do not save other unsaved
attributes. A write is skipped while the stored value is newer than the update threshold.

## Opting Users Out

Register one callback in a service provider's `boot()` method. Rejected users get no event and no writes. The callback
receives every model that uses the trait, so type-hint `Model` when more than one model does:

```php
LastSeen::trackUsing(fn (User $user): bool => ! $user->hide_activity_status);
```

The callback runs for every request of an authenticated user and once more when a write is due, so keep it free of
queries. It also fits requests that are not the user's own activity, for example while someone impersonates the user:
`fn (User $user): bool => ! session()->has('impersonator_id')`.

## Choosing Which Requests Count

Every request through the middleware counts, background polling included. Leave requests out with a callback that returns
`true` for a request that does not count, registered once in a service provider's `boot()` method:

```php
LastSeen::ignoreRequestsUsing(fn (Request $request): bool => $request->is('api/notifications/poll'));
```

It runs after the request, so `$request->route()` is available (or `null` for an unmatched request), and before the
middleware resolves the user: an ignored request skips that, the `trackUsing()` call and the `UserWasActiveEvent`. It
runs for guests too, so keep it cheap. There is one callback: a second call replaces the first, so combine all conditions
in one. An exception in it is reported and the request is not recorded. It only concerns the middleware, not
`LastSeen::record()` or `updateLastSeenAt()`. A filter on Livewire's update route or header also drops real actions,
because polls and actions share them: use a dedicated endpoint or a marker the application adds. Keep Inertia
prefetch requests (`Purpose: prefetch`): a visit to a cached prefetched page sends no further request. Inertia polls are
not marked, so pass a header of your own via `usePoll(..., { headers: {...} })` and ignore that. For integrations with
an API token, check the token itself: Sanctum's `tokenCan()` is also true for session-cookie requests and for `*` tokens.
For users who work without sending requests, add a route that only carries `UpdateLastSeenMiddleware` and let the front
end call it on interaction.

## Several User Models

The published migration only adds `last_seen_at` to the table of `models.user`. Add the column to the table of every
other model that uses the trait with a migration of your own, and type-hint `Model` in the `trackUsing()` callback. The
middleware records the user of the guard that is authenticated after the request, e.g. on routes with `auth:admin`.

## Events

The middleware fires `Taldres\LastSeen\Events\UserWasActiveEvent` once per request for the user authenticated after the
request, so logout requests are not recorded. Recording activity never fires it again. Exceptions from the guard, the
`trackUsing()` and `ignoreRequestsUsing()` callbacks and listeners are reported without changing the response.

`Taldres\LastSeen\Events\LastSeenRecordedEvent` fires whenever `last_seen_at` was actually written (middleware,
`updateLastSeenAt()` or `LastSeen::record()`), about once per user and update threshold. Use it instead of
`UserWasActiveEvent` for work that must not run on every request. It carries `$user` (already updated),
`$previousLastSeenAt`, `$recordedAt` and `$becameRecentlySeen`. `$recordedAt` is the written value as a freshly loaded
model reads it, so it equals the stored `last_seen_at` until the next write and stays fixed when a queued listener
reloads a user who was seen again since; use it with the user key to deduplicate. Created by hand without it, it is the
current time. `$becameRecentlySeen` means `recentlySeen()` was false before this write and is true now, which includes
never seen and just created models. That is not a presence signal, it only marks the start of a new stretch of activity.
An unreadable previous value (model loaded without the column, or a cast that returns no `Carbon`) gives `null` and
`false`; a future value gives `false`. The previous value and the flag come from the model instance, so a cached user,
parallel requests with an update threshold of 0, a concurrent `forgetLastSeenAt()` or a retry after a rollback can make
them wrong: treat `becameRecentlySeen` as a hint and make listeners idempotent.

The event implements `ShouldDispatchAfterCommit`, so a rolled back write fires nothing (with several connections an
unrelated rollback can drop it). A listener that throws makes `record()` throw after the write, but inside an open
transaction the listener runs at commit, so the exception comes out of the commit, can skip later `DB::afterCommit()`
callbacks and is not caught by the middleware. Prefer queued listeners, and queue only on `becameRecentlySeen` with
`shouldQueue(LastSeenRecordedEvent $event): bool`. A listener must not record the same user again. Queued listeners copy
the user key, `previousLastSeenAt` and `recordedAt` into `jobs` and `failed_jobs`, which `forgetLastSeenAt()` does not
remove: set `$deleteWhenMissingModels = true` on them. There is no event for users who stop being recently seen.

For inactive users, schedule a job (`Schedule::call(...)->daily()->name(...)->onOneServer()`) over
`lastSeenBefore(now()->subDays(30))`, iterate with `lazyById()` (offset chunks skip rows whose filter column changes)
and remind once per inactive period by storing the reminder time in an application column and only selecting rows
where it is null or older than `last_seen_at` (`orWhereColumn`). For returning users, use a queued
`LastSeenRecordedEvent` listener whose `shouldQueue()` compares `previousLastSeenAt` with `recordedAt`, and
deduplicate with `Cache::add("...:{$event->user->getKey()}:{$event->recordedAt->getTimestamp()}", ...)`.

## Testing

- Freeze or travel in time (`$this->travel(61)->seconds()`) to test the thresholds.
- Use `LastSeen::fake()` to stop all writes and assert with `assertRecorded($user)`, `assertRecordedTimes($user, 2)`,
  `assertNotRecorded($user)`, `assertNothingRecorded()`, `assertForgotten($user)`, `assertNotForgotten($user)` and
  `assertNothingForgotten()`. Each takes a model or a `fn (Model $user): bool` callback. The fake keeps the config and
  the `trackUsing()` and `ignoreRequestsUsing()` callbacks but ignores the update threshold, so every `record()` call for a
  tracked user counts.
- Use `Event::fake([UserWasActiveEvent::class])` to assert activity detection without writes.
- Use `Event::fake([LastSeenRecordedEvent::class])` to assert a write; the row is still written. `LastSeen::fake()`
  fires no `LastSeenRecordedEvent`, so dispatch it yourself to test listeners.
- Disable tracking with `config(['last-seen.enabled' => false])` or `LastSeen::trackUsing(fn () => false)`.
- Mock the facade with `LastSeen::partialMock()`, or stub both `shouldIgnoreRequest()` and `shouldTrack()`: the middleware
  calls the first for every request and a full mock reports the missing method instead of throwing, so an expectation on
  `shouldTrack()` or `record()` is never met. With `LastSeen::spy()` both return
  `false` and nothing is recorded.

## Anti-Patterns

- Mass-assigning `last_seen_at`: it is not fillable. Use `LastSeen::record()` or `forceFill()`.
- Calling `updateLastSeenAt()` in controllers when the middleware already runs on the route.
- Comparing `last_seen_at` with a hand-written cutoff instead of `onlyRecentlySeen()`, `notRecentlySeen()` or
  `lastSeenBefore()` or `lastSeenSince()`, which also respect the model's date format and casts.
- Negating `onlyRecentlySeen()` by hand with `whereNot`: use `notRecentlySeen()`.
- Calling `LastSeen::trackUsing()` during a request: the callback stays active for later requests in Octane and queue
  workers.
- Treating `last_seen_at` as exact: it can lag behind the latest request by up to the update threshold.
- Setting the recently seen threshold below the update threshold: an active user then drops out of `onlyRecentlySeen()`
  between two writes.
- Running queries in the `trackUsing()` or `ignoreRequestsUsing()` callback, which run on every request.
- Calling `LastSeen::ignoreRequestsUsing()` during a request: the callback stays active for later requests in Octane and
  queue workers.
- Ignoring Livewire's update route or `X-Livewire` header to stop polling: it also carries real user actions.
- Ignoring Inertia prefetch requests (`Purpose: prefetch`): the later visit is served from the prefetch cache.
- Doing heavy work in a `UserWasActiveEvent` listener, which runs on every request: use `LastSeenRecordedEvent`.
- Long transactions that read first and later write the users row: on MariaDB 11.6+ (`innodb_snapshot_isolation` on)
  they fail with error 1020 when `last_seen_at` was written in between. Use `DB::transaction(..., attempts: 3)`, a short
  transaction for the users write, or `READ COMMITTED`; `lockForUpdate()` first makes requests of that user wait.
- Calling `recentlySeen()` on users loaded with `select()` without `last_seen_at`: it returns `false`, or throws in
  strict mode.
