[![Packagist Version](https://img.shields.io/packagist/v/taldres/laravel-last-seen)](https://packagist.org/packages/taldres/laravel-last-seen)
![Tests](https://github.com/Taldres/laravel-last-seen/actions/workflows/run-tests.yml/badge.svg)

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
PHP 8.2 or higher (8.3 or higher for Laravel 13)

### Supported Laravel Versions

| Laravel Version | Package Version | PHP Version |
|:----------------|:----------------|:------------|
| `^12.0`         | `^1.0`          | `^8.2`      |
| `^13.0`         | `^1.0`          | `^8.3`      |

Laravel 11 is supported up to package version `0.4.x`.

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
   
4. Run the migration to create the necessary database table:

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
   
6. Add the middleware to your `web` or `api` middleware group or any other endpoint:

    ```php
    // ...
    \Taldres\LastSeen\Middleware\UpdateLastSeenMiddleware::class,
    // ...
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

In the `config/last-seen.php` file, you can specify the User model to be used for tracking last seen timestamps:

- `user`: The fully qualified class name of the User model to be used for tracking last seen timestamps.

All other settings—such as enabling/disabling the feature, update thresholds, and recently seen thresholds—can be controlled via environment variables in your `.env` file:

- `LAST_SEEN_ENABLED`: Enables or disables the package globally (default: true). It affects only the `updateLastSeenAt` method and the middleware.
- `LAST_SEEN_UPDATE_THRESHOLD`: Minimum seconds between last_seen_at updates (default: 60)
- `LAST_SEEN_RECENTLY_SEEN_THRESHOLD`: Seconds a user is considered recently seen after last activity (default: 300)

Each setting has a default value, so you only need to override them if you want to change the default behavior.

## Usage

### Checking Activity

- `$user->recentlySeen()`: Returns `true` if `last_seen_at` is at most `LAST_SEEN_RECENTLY_SEEN_THRESHOLD` seconds ago.
- `User::onlyRecentlySeen()`: Query scope to get only recently seen users, using the same rule as `recentlySeen()`.

A `last_seen_at` in the future, e.g. caused by clock drift between servers, counts as recently seen.

### Updating Activity

- `$user->updateLastSeenAt()`: Writes `last_seen_at` if the user is tracked and the configured update threshold has
  passed, and returns whether it wrote. Only `last_seen_at` is written: no model events are fired, the model's
  `updated_at` timestamp is left untouched and other unsaved changes on the model are not persisted.
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
```

The facade resolves `Taldres\LastSeen\LastSeenManager` from the container, so you can also inject the manager
directly. The trait, the middleware and the event listener all use it, so the same rules apply everywhere.

### Events

The package fires a `UserWasActiveEvent` whenever activity of a tracked user is detected. You can listen to this event
for custom logic. Recording the activity never fires the event again.

### Manually Dispatching the Event

You can also dispatch the `UserWasActiveEvent` from your own application code:

```php
use Taldres\LastSeen\Events\UserWasActiveEvent;
use Illuminate\Support\Facades\Event;

Event::dispatch(new UserWasActiveEvent($user));
```

## License

MIT
