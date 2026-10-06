<p align="center">
    <img src="https://raw.githubusercontent.com/Taldres/laravel-last-seen/main/.github/assets/laravel-last-seen-social-preview.jpg" alt="Laravel Last Seen: user activity tracking for Laravel">
</p>

[![Packagist Version](https://img.shields.io/packagist/v/taldres/laravel-last-seen)](https://packagist.org/packages/taldres/laravel-last-seen)
![Tests](https://github.com/Taldres/laravel-last-seen/actions/workflows/tests.yml/badge.svg)

# Laravel Last Seen

A simple Laravel package to track a user's last seen and recently seen status. This package provides traits, middleware, events, and configuration to easily record and query when a user was last active in your Laravel application.

## Features

- Automatically update the `last_seen_at` timestamp for users
- Middleware to detect user activity
- Event-based architecture for extensibility
- Query scopes and helper methods to check if a user was recently seen
- Configurable thresholds for updating and checking activity
- Migration publishing for easy setup

## Requirements

### PHP
PHP 8.3 or higher

### Supported Laravel Versions

| Laravel Version | Package Version | PHP Version |
|:----------------|:----------------|:------------|
| `^12.0`         | `^1.0`          | `^8.3`      |
| `^13.0`         | `^1.0`          | `^8.3`      |

Laravel 11 and PHP 8.2 are supported up to package version `0.4.x`.

## Installation

1. Install the package via Composer:

    ```bash
    composer require taldres/laravel-last-seen
    ```

2. Publish the migration and configuration files:
    ```bash
    php artisan vendor:publish --provider="Taldres\LastSeen\LastSeenServiceProvider"
    ```
   
3. Clear the configuration cache to ensure the new settings are loaded:

    ```bash
    php artisan optimize:clear
    # or
    php artisan config:clear
    ```
   
4. Run the migration to add the `last_seen_at` column to your users table:

    ```bash
    php artisan migrate
    ```

   If your users table already has a `last_seen_at` column, the migration stops with an error instead of taking over a
   column it would drop on rollback. Delete the published migration in that case.
   
5. Add the `Taldres\LastSeen\Trait\LastSeen` trait to your User model:

    ```php
    use Taldres\LastSeen\Trait\LastSeen;
    
    class User extends Authenticatable
    {
        use LastSeen;
        // ...
    }
    ```
   
6. Add the middleware to your `web` or `api` middleware group in `bootstrap/app.php`, or to individual routes:

    ```php
    // bootstrap/app.php
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \Taldres\LastSeen\Middleware\UpdateLastSeenMiddleware::class,
        ]);

        // Token-based APIs, e.g. with Laravel Sanctum:
        $middleware->api(append: [
            \Taldres\LastSeen\Middleware\UpdateLastSeenMiddleware::class,
        ]);
    })
    ```

   The middleware resolves the authenticated user after the request has been handled, so it also works when
   authentication happens later in the stack, e.g. through a route-level `auth:sanctum` middleware.

## Configuration

If necessary or in case of a newer version, you can publish the configuration file to customize the package settings:

```bash
php artisan vendor:publish --provider="Taldres\LastSeen\LastSeenServiceProvider" --tag="config"

```
or this command to force overwrite the existing configuration file:

```bash
php artisan vendor:publish --provider="Taldres\LastSeen\LastSeenServiceProvider" --tag="config" --force
```

---

In the `config/last-seen.php` file, you can specify the User model:

- `models.user`: The fully qualified class name of the User model. The migration uses it to find the users table.

All other settings—such as enabling/disabling the feature, update thresholds, and recently seen thresholds—can be controlled via environment variables in your `.env` file:

- `LAST_SEEN_ENABLED`: Enables or disables the package globally (default: true). It affects only the `updateLastSeenAt` method and the middleware.
- `LAST_SEEN_UPDATE_THRESHOLD`: Minimum seconds between last_seen_at updates (default: 60)
- `LAST_SEEN_RECENTLY_SEEN_THRESHOLD`: Seconds a user is considered recently seen after last activity (default: 300)

Both thresholds must be integers of 0 or more. Other values throw an `InvalidArgumentException`.

Each setting has a default value, so you only need to override them if you want to change the default behavior.

## Usage

### How Activity Is Tracked

- Every request that passes through the middleware with an authenticated user counts as activity. This includes
  background requests such as polling, which can keep an otherwise idle user "recently seen". Leave the middleware off
  routes that should not count as activity.
- For each such request of a tracked user the middleware fires a `UserWasActiveEvent`. The event means "activity
  detected", not "timestamp written": `last_seen_at` is only written once `LAST_SEEN_UPDATE_THRESHOLD` seconds have
  passed since the stored value, so it can lag behind the latest activity by up to that many seconds. Parallel requests
  write it only once.
- The middleware records the user that is authenticated once the request has been handled: the user of the default
  guard, or of the guard an `auth:<guard>` middleware selected. Logout requests are therefore not recorded, and when a
  request switches users, for example while an admin impersonates someone, only the final user is.
- Error responses count as activity too. Registering the middleware twice, for example globally and on a route, still
  records each request once.
- If recording fails, for example because of a database error or an exception in one of your listeners, the exception
  is reported and the response is returned unchanged.

### Checking Activity

- `$user->recentlySeen()`: Returns `true` if `last_seen_at` is at most `LAST_SEEN_RECENTLY_SEEN_THRESHOLD` seconds ago.
- `User::onlyRecentlySeen()`: Query scope to get only recently seen users, using the same rule as `recentlySeen()`.

A `last_seen_at` in the future, e.g. caused by clock drift between servers, counts as recently seen until the next
recorded activity replaces it.

Keep `app.timezone` on UTC. With another timezone, `last_seen_at` is stored as local time, which is ambiguous for an hour
when daylight saving time ends, just like Laravel's own timestamps.

### Updating Activity

- `$user->updateLastSeenAt()`: Writes `last_seen_at` if the user is tracked and the configured update threshold has
  passed, and returns whether the stored timestamp changed. With an update threshold of `0` it writes on every call
  unless `last_seen_at` already holds the current time, for example within the same second with the default date
  format. Only `last_seen_at` is written: no model events are fired, the model's `updated_at` timestamp is left
  untouched and other unsaved changes on the model are not persisted.
- `$user->forgetLastSeenAt()`: Sets `last_seen_at` to `null`, again without touching `updated_at` or other unsaved
  changes. It works even when the package is disabled.

`last_seen_at` is not added to your model's `$fillable`. If you need to mass-assign it, add it there yourself or use `forceFill()`.

### Facade

The `LastSeen` facade offers the same operations for code outside the model, such as controllers, jobs and commands:

```php
use Taldres\LastSeen\Facades\LastSeen;

LastSeen::record($user);         // same as $user->updateLastSeenAt()
LastSeen::forget($user);         // same as $user->forgetLastSeenAt()
LastSeen::recentlySeen($user);   // same as $user->recentlySeen()
LastSeen::recentlySeenSince();   // earliest last_seen_at that still counts as recently seen
LastSeen::shouldTrack($user);    // whether last_seen_at may be written for this user
LastSeen::trackUsing($callback); // decide per user whether to track, see Privacy
```

The facade is also registered as the global alias `LastSeen`. It resolves `Taldres\LastSeen\LastSeenManager` from
the container, so you can also inject the manager directly. The trait, the middleware and the event listener all use it, so the same rules apply everywhere.

### Events

The package fires a `UserWasActiveEvent` whenever activity of a tracked user is detected. You can listen to this event
for custom logic. Recording the activity never fires the event again.

### Testing Your Application

- Use `Event::fake([UserWasActiveEvent::class])` to assert that activity was detected without writing anything.
- Disable tracking with `config(['last-seen.enabled' => false])` or `LastSeen::trackUsing(fn () => false)`.
- When mocking the facade, use `LastSeen::partialMock()` or stub `shouldTrack()`. A `LastSeen::spy()` returns `false`
  from `shouldTrack()`, so the middleware never calls `record()`.

### Manually Dispatching the Event

You can also dispatch the `UserWasActiveEvent` from your own application code:

```php
use Taldres\LastSeen\Events\UserWasActiveEvent;
use Illuminate\Support\Facades\Event;

Event::dispatch(new UserWasActiveEvent($user));
```

## AI Agents

The package ships a [Laravel Boost](https://laravel.com/framework/docs/boost) skill that teaches coding agents how to use it.
Boost offers to install it when you run `php artisan boost:install`, or `php artisan boost:update --discover` in an
existing setup.

## Privacy

`last_seen_at` is tied to a user, so it is personal data. Your application decides the purpose, the legal basis, how
users are informed and how long the data is kept. The package cannot make an application compliant on its own, but it
keeps the data small and under your control:

- It stores a single `last_seen_at` column on your users table. Each write overwrites the previous value. There is no
  activity history, and no IP address or user agent is stored.
- It does not send any data to external services.
- Who may see another user's activity status is up to your application, for example through policies.

### Opting Users Out

Register a callback in a service provider to decide per user whether activity is tracked, for example based on a user
setting. The package does not expect any particular column for this:

```php
use App\Models\User;
use Taldres\LastSeen\Facades\LastSeen;

public function boot(): void
{
    LastSeen::trackUsing(fn (User $user): bool => ! $user->hide_activity_status);
}
```

For users the callback rejects, the middleware fires no `UserWasActiveEvent` and `updateLastSeenAt()` leaves
`last_seen_at` untouched. The callback receives every model that uses the trait, so type-hint `Model` if more than one
of your models does. Register it once while booting: it stays active for all later requests, also in Octane or queue
workers.

### Deleting the Timestamp

- `$user->forgetLastSeenAt()` sets `last_seen_at` to `null`. Combine it with an opt-out, otherwise the next request
  writes it again. It throws a `LogicException` for a model that was loaded without its primary key.
- When a user is deleted, `last_seen_at` is deleted with the row.
- `LAST_SEEN_ENABLED=false` only stops new writes. It does not delete stored values.

## Upgrading

See [UPGRADE.md](UPGRADE.md) for the changes between major versions, including the upgrade from 0.4 to 1.0.

## Contributing

See [CONTRIBUTING](.github/CONTRIBUTING.md). Please report security vulnerabilities as described in
[SECURITY](.github/SECURITY.md) instead of opening a public issue.

## License

MIT
