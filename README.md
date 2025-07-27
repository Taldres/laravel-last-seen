![Packagist Version](https://img.shields.io/packagist/v/taldres/laravel-last-seen)


# Laravel Last Seen

A simple Laravel package to track a user's last seen and recently seen status. This package provides traits, middleware, events, and configuration to easily record and query when a user was last active in your Laravel application.

## Features

- Automatically update the `last_seen` timestamp for users
- Middleware to detect user activity
- Event-based architecture for extensibility
- Query scopes and helper methods to check if a user was recently seen
- Configurable thresholds for updating and checking activity
- Migration publishing for easy setup

## Installation

Install the package via Composer:

```bash
composer require taldres/laravel-last-seen
```

## Configuration

Publish the configuration file:

```bash
php artisan vendor:publish --provider="Taldres\\LastSeen\\LastSeenServiceProvider" --tag="config"
```

This will create a `config/last-seen.php` file where you can adjust thresholds and settings:

- `enabled`: Enables or disables the package globally. If disabled, only updating is deactivated; reading is still possible.
- `user_model`: The User model class
- `update_threshold`: Minimum seconds between last_seen updates
- `recently_seen_threshold`: Seconds a user is considered recently seen after last activity

You can also copy the example environment variables from `.env.example` to your `.env` file.

## Configuration and Migration Publishing

To publish both the configuration file and the migration, run:

```bash
php artisan vendor:publish --provider="Taldres\\LastSeen\\LastSeenServiceProvider"
```

This will copy both the configuration and the migration into your project.

## Migration

After publishing, run the migration to update your database schema:

```bash
php artisan migrate
```

## Usage

### Add the Trait

Add the `Taldres\LastSeen\Trait\LastSeen` trait to your User model:

```php
use Taldres\LastSeen\Trait\LastSeen;

class User extends Authenticatable
{
    use LastSeen;
    // ...
}
```

### Register the Middleware

Add the middleware to your `web` or `api` middleware group or any other endpoint:

```php
// ...
\Taldres\LastSeen\\Middleware\UpdateLastSeenMiddleware::class,
// ...
```

### Checking Activity

- `$user->recentlySeen()`: Returns `true` if the user was active within the configured threshold.
- `User::onlyRecentlySeen()`: Query scope to get only users recently seen.

### Events

The package fires a `UserWasActiveEvent` whenever user activity is detected. You can listen to this event for custom logic.

### Manually Dispatching the Event

You can also dispatch the `UserWasActiveEvent` from your own application code:

```php
use Taldres\LastSeen\Events\UserWasActiveEvent;
use Illuminate\Support\Facades\Event;

Event::dispatch(new UserWasActiveEvent($user));
```

## Testing

Run the tests with:

```bash
vendor/bin/pest
```

## License

MIT
