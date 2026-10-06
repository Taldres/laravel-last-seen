# Upgrade Guide

## Upgrading to 1.0 from 0.4

1.0 is the first stable release. From now on the package follows [Semantic Versioning](https://semver.org/):
breaking changes only happen in major releases.

### Requirements

- Laravel 12 or 13 is required. Laravel 11 is no longer supported; stay on `0.4.x` if you cannot upgrade.
- PHP 8.3 or higher is required. PHP 8.2 is no longer supported; stay on `0.4.x` if you cannot upgrade.

Update the constraint in your `composer.json`:

```bash
composer require taldres/laravel-last-seen:^1.0
```

### Upgrading With an AI Agent

The package ships the [Laravel Boost](https://laravel.com/framework/docs/boost) skill `upgrade-laravel-last-seen-v1`,
which walks a coding agent through the steps in this guide. 0.4 shipped no Boost skill, so add the package to Boost
once after updating it. Run the command in your terminal, because Boost only asks for new packages interactively:

```bash
php artisan boost:update
```

Select `taldres/laravel-last-seen`, then ask your agent to upgrade Laravel Last Seen to 1.0, or invoke the skill
directly, for example with `/upgrade-laravel-last-seen-v1` in Claude Code. Without Boost, point your agent to
`vendor/taldres/laravel-last-seen/resources/boost/skills/upgrade-laravel-last-seen-v1/SKILL.md`.

### `last_seen_at` Is No Longer Fillable

The `LastSeen` trait no longer adds `last_seen_at` to your model's `$fillable`. Before, the column could be
mass-assigned from request data, which let users set their own activity status.

If your code mass-assigns the column, for example with `User::create([..., 'last_seen_at' => ...])`, `update()` or
`fill()`, Eloquent now discards the value silently, or throws an exception if you enabled
`Model::preventSilentlyDiscardingAttributes()`. Add it to `$fillable` yourself, or set it explicitly:

```php
$user->forceFill(['last_seen_at' => now()])->save();
```

### `updateLastSeenAt()` Only Writes `last_seen_at`

`updateLastSeenAt()` now writes the `last_seen_at` column directly, without saving the model:

- `updated_at` is no longer changed. In 0.4 every update of `last_seen_at` also touched `updated_at`. If you relied
  on `updated_at` reflecting user activity, use `last_seen_at` instead.
- Other unsaved changes on the model are no longer persisted along with `last_seen_at`. Call `save()` yourself if
  you need them stored.
- Parallel requests no longer write the timestamp more than once within the update threshold.
- A new timestamp is written as soon as `LAST_SEEN_UPDATE_THRESHOLD` seconds have passed. 0.4 waited until more than
  that.

### The Middleware No Longer Calls `updateLastSeenAt()`

In 0.4 the event listener called `updateLastSeenAt()` on the user model, so overriding the method changed what the
middleware recorded. The middleware and the listener now record through `LastSeenManager::record()`, and only for
models that use the `LastSeen` trait. An overridden `updateLastSeenAt()` only runs when your own code calls it.

Move logic that skips some users to `LastSeen::trackUsing()`, and other custom logic to a listener for
`UserWasActiveEvent`.

### The Middleware Runs After the Request

`UpdateLastSeenMiddleware` now resolves the authenticated user and fires `UserWasActiveEvent` after the request has
been handled, instead of before. This makes it work when authentication happens later in the stack, for example
through a route-level `auth:sanctum` middleware.

As a consequence, `last_seen_at` is no longer updated before your controller runs. If a controller relied on seeing
the fresh timestamp within the same request, call `$user->updateLastSeenAt()` there yourself. The request that logs a
user in is now recorded, the request that logs a user out is not.

Exceptions while recording activity, for example from your own `UserWasActiveEvent` listeners or your `trackUsing()`
callback, are now reported instead of replacing the response with an error page.

Authenticated users that are not Eloquent models, for example a `GenericUser` from the `database` user provider, are
now ignored instead of causing an error.

### `recentlySeen()` Includes the Threshold

`recentlySeen()` now treats a user seen exactly `LAST_SEEN_RECENTLY_SEEN_THRESHOLD` seconds ago as recently seen.
This matches the `onlyRecentlySeen()` scope, which already included the threshold.

### Thresholds Must Be Integers of 0 or More

The `update_threshold` and `recently_seen_threshold` config values are now read as integers and throw an exception
otherwise. The published config file has always cast them, so this only affects code that sets them at runtime, for
example `config(['last-seen.update_threshold' => '60'])`. Pass integers instead.

Negative values now throw an exception as well, also when they come from `LAST_SEEN_UPDATE_THRESHOLD` or
`LAST_SEEN_RECENTLY_SEEN_THRESHOLD` in your `.env` file.

### Migration

Migrations you have already published are not affected. Newly published migrations:

- add an index on `last_seen_at`, which speeds up `onlyRecentlySeen()` on large tables. To add it to an existing
  installation, create a migration with:

    ```php
    Schema::table('users', function (Blueprint $table) {
        $table->index('last_seen_at');
    });
    ```

- stop with an error if the users table already has a `last_seen_at` column, instead of silently taking it over and
  dropping it on rollback.

### Removed `LAST_SEEN_USER_MODEL`

`.env.example` no longer lists `LAST_SEEN_USER_MODEL`. The variable never had an effect: the user model is set in
`config/last-seen.php` under `models.user`. You can remove it from your `.env`.
