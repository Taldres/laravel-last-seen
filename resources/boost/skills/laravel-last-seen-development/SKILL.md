---
name: laravel-last-seen-development
description: Track and query when users were last active with taldres/laravel-last-seen, including the last_seen_at column, recentlySeen(), the onlyRecentlySeen() scope, UpdateLastSeenMiddleware, the LastSeen facade and opting users out.
---

# Laravel Last Seen Development

## When to use this skill

Use this skill when an application needs to know when users were last active, show who is online, or let users hide
their activity, and `taldres/laravel-last-seen` is installed.

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
seen, default 300).

## Reading Activity

```php
$user->last_seen_at;                                // Carbon instance or null
$user->recentlySeen();                              // seen within the recently seen threshold
User::onlyRecentlySeen()->get();                    // same rule as a query scope, also inside joins
```

## Writing and Deleting Activity

The middleware records activity automatically. Record it manually only outside HTTP requests, for example in a job:

```php
use Taldres\LastSeen\Facades\LastSeen;

LastSeen::record($user);    // same as $user->updateLastSeenAt(), returns whether it wrote
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

## Events

The middleware fires `Taldres\LastSeen\Events\UserWasActiveEvent` once per request for the user authenticated after the
request, so logout requests are not recorded. Listen to it for custom logic. Recording activity never fires the event
again, and exceptions from listeners are reported without changing the response.

## Testing

- Freeze or travel in time (`$this->travel(61)->seconds()`) to test the thresholds.
- Use `Event::fake([UserWasActiveEvent::class])` to assert activity detection without writes.
- Disable tracking with `config(['last-seen.enabled' => false])` or `LastSeen::trackUsing(fn () => false)`.
- Mock the facade with `LastSeen::partialMock()` or stub `shouldTrack()`. With `LastSeen::spy()`, `shouldTrack()`
  returns `false` and nothing is recorded.

## Anti-Patterns

- Mass-assigning `last_seen_at`: it is not fillable. Use `LastSeen::record()` or `forceFill()`.
- Calling `updateLastSeenAt()` in controllers when the middleware already runs on the route.
- Comparing `last_seen_at` with a hand-written cutoff instead of `onlyRecentlySeen()`, which also respects the
  model's date format and casts.
- Calling `LastSeen::trackUsing()` during a request: the callback stays active for later requests in Octane and queue
  workers.
- Treating `last_seen_at` as exact: it can lag behind the latest request by up to the update threshold.
