# Installation

## Requirements

### PHP
PHP 8.3 or higher

### Supported Laravel Versions

| Laravel Version | Package Version | PHP Version |
|:----------------|:----------------|:------------|
| `^12.0`         | `^1.0`          | `^8.3`      |
| `^13.0`         | `^1.0`          | `^8.3`      |

Laravel 11 and PHP 8.2 are supported up to package version `0.4.x`.

### Supported Databases

The test suite runs against the same database versions as `laravel/framework`: SQLite, MySQL 5.7, 8.4 and 9.7,
MariaDB 10.11, 11.8 and 12.3, PostgreSQL 10, 14 and 18, and SQL Server 2017, 2019, 2022 and 2025.

SQL Server stores `last_seen_at` in a `datetime` column, which rounds to about 3 milliseconds. The thresholds are
whole seconds, so a write can happen a few milliseconds early at most, which makes no difference in practice.

A custom `$dateFormat` on your model that contains a timezone offset, such as ISO 8601 with `P`, only works where the
database accepts it in a `datetime` column. MySQL before 8.0.19, MariaDB and SQL Server do not.

## Installation Steps

1. Install the package via Composer:

    ```bash
    composer require taldres/laravel-last-seen
    ```

2. Publish the migration:

    ```bash
    php artisan vendor:publish --provider="Taldres\LastSeen\LastSeenServiceProvider" --tag=last-seen-migrations
    ```

   The migration finds your users table through the `models.user` setting. You only need to publish the configuration
   file to change it or other settings, see [Configuration](configuration.md).

3. Run the migration to add the `last_seen_at` column to your users table:

    ```bash
    php artisan migrate
    ```

   If your users table already has a `last_seen_at` column, the migration stops with an error instead of taking over a
   column it would drop on rollback. Delete the published migration in that case.

4. Add the `Taldres\LastSeen\Trait\LastSeen` trait to your User model:

    ```php
    use Taldres\LastSeen\Trait\LastSeen;
    
    class User extends Authenticatable
    {
        use LastSeen;
        // ...
    }
    ```
   
5. Add the middleware to your `web` or `api` middleware group in `bootstrap/app.php`, or to individual routes:

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

6. Check the setup:

    ```bash
    php artisan about --only=last_seen
    ```

   The "Last Seen" section shows whether the package is enabled, the user model and whether it uses the trait, and both
   thresholds. It marks a missing trait, an invalid threshold and a recently seen threshold that is not above the update
   threshold.

## Removing the Package

Do these steps before you run `composer remove taldres/laravel-last-seen`:

1. Roll back the published migration if you want to drop the `last_seen_at` column. Its `down()` method reads the
   user model from the package configuration, so it cannot run once the package is gone.
2. Delete the published migration file. After the package is removed, `php artisan migrate` on a fresh database fails
   with it. If you keep the column, add a migration of your own for it.
3. Remove the `LastSeen` trait from your user model and the middleware from `bootstrap/app.php`.
4. Delete `config/last-seen.php` if you published it. Files published with 1.6.1 or 1.6.2 call a class of the package, so the
   application cannot boot without the package while such a file exists.
