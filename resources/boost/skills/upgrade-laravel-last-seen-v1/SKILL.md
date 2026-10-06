---
name: upgrade-laravel-last-seen-v1
description: Upgrade taldres/laravel-last-seen from 0.x to 1.0 and fix code that relies on the old behaviour, such as mass-assigned last_seen_at, updated_at used as an activity signal, overrides of updateLastSeenAt(), the middleware running before the controller and non-integer thresholds. Do not use for regular work with the package, use laravel-last-seen-development instead.
---

# Laravel Last Seen Upgrade

## When to use this skill

Use this skill when an application moves `taldres/laravel-last-seen` from a 0.x version to 1.x. Work through the
steps in order. Search the whole project, including `app/`, `config/`, `database/`, `routes/`, `resources/` and
`tests/`. For regular work with the package, use the `laravel-last-seen-development` skill.

Some changes need a decision from the user. Ask instead of guessing where a step says so.

## 1. Check the Starting Point

- Run `composer show taldres/laravel-last-seen`. If 1.x is already installed, continue with step 3.
- 1.0 requires PHP 8.3 or higher and Laravel 12 or 13. If the application runs on Laravel 11 or PHP 8.2, stop and
  tell the user that the framework has to be upgraded first, in a separate change.
- Run the test suite once, so you know which tests already failed before the upgrade.

## 2. Update the Package

```bash
composer require taldres/laravel-last-seen:^1.0
```

## 3. Mass Assignment of `last_seen_at`

The `LastSeen` trait no longer adds `last_seen_at` to `$fillable`. Eloquent now discards the value silently, or
throws a `MassAssignmentException` with `Model::preventSilentlyDiscardingAttributes()`.

Search for `last_seen_at` passed to `create()`, `make()`, `new User([...])`, `fill()`, `update()`, `firstOrCreate()`,
`firstOrNew()` and `updateOrCreate()` on models that use the trait.

These cases are not affected and need no change:

- Model factories, including attributes passed to `User::factory()->create([...])`.
- Seeders run through `db:seed` or `$this->seed()`, and code inside `Model::unguarded()`.
- `forceFill()`, `forceCreate()` and direct assignment such as `$user->last_seen_at = now()`.
- Models with an empty `$fillable` that do not guard `last_seen_at`, for example with `$guarded = []`. There the
  column stays mass-assignable, so make sure no request data can reach it.

Fix each affected call:

- To store the current time, use `$user->updateLastSeenAt()` or `LastSeen::record($user)`.
- To store any other value, for example in an import or a test, use `$user->forceFill(['last_seen_at' => $value])`.
- Never add `last_seen_at` to `$fillable` on your own. If the user wants it fillable, make sure no request data can
  reach it, otherwise users can set their own activity status.

## 4. `updated_at` No Longer Reflects Activity

In 0.x every write of `last_seen_at` also changed `updated_at`. 1.0 writes only `last_seen_at`.

Search for code that reads `updated_at` of a model that uses the trait, for example `orderBy('updated_at')`,
`where('updated_at', ...)`, `->updated_at->diffForHumans()` in views, or cache keys built from `updated_at`.

Ask the user what each place means:

- Last activity: switch to `last_seen_at`, `recentlySeen()` or the `onlyRecentlySeen()` scope.
- Last change of the record: keep it. It now works as intended.

Tests that expect `updated_at` to change after a request or after `updateLastSeenAt()` have to be changed.

## 5. `updateLastSeenAt()` Saves Only `last_seen_at`

In 0.x `updateLastSeenAt()` saved the model, so other unsaved changes were stored along with it. Find calls to
`updateLastSeenAt()` on a model instance whose attributes were changed before and not saved. Add an explicit
`save()` there.

`updateLastSeenAt()` now returns whether the stored timestamp changed. Callers that ignore the result need no change.

## 6. Overrides of `updateLastSeenAt()`

In 0.x the event listener called `updateLastSeenAt()` on the user model, so overriding the method changed what the
middleware recorded. In 1.0 the middleware and the listener record through `LastSeenManager::record()`, and only for
models that use the `LastSeen` trait. An override now only runs when the application calls it directly.

Search for models that define `updateLastSeenAt()`, alias it with `use LastSeen { ... }`, or implement it without
using the trait. Then:

- Move logic that skips some users to a callback in a service provider's `boot()` method, and remove the override:

    ```php
    LastSeen::trackUsing(fn (User $user): bool => ! $user->hide_activity_status);
    ```

- Move other custom logic to a listener for `Taldres\LastSeen\Events\UserWasActiveEvent`.
- Add the `LastSeen` trait to models that implemented the method without it.

## 7. The Middleware Runs After the Request

`UpdateLastSeenMiddleware` now resolves the user and records the activity after the request has been handled.

- Search controllers, views, components and API resources that read `last_seen_at` or `recentlySeen()` of the
  authenticated user and expect the value of the current request. Call `$request->user()->updateLastSeenAt()` before
  reading it, or ask the user whether the previous value is good enough.
- The request that logs a user in is now recorded, the request that logs a user out is not. Adjust tests that expect
  the opposite.
- Exceptions from `UserWasActiveEvent` listeners and the `trackUsing()` callback are now reported instead of failing
  the request. Tests that expect a failed response have to assert the reported exception instead, for example with
  `Exceptions::fake()` and `Exceptions::assertReported()`.
- The middleware no longer has to run after the authentication middleware, and registering it more than once is
  harmless, because it records once per request. Leave existing registrations alone unless the user wants them
  simplified.

## 8. Thresholds at the Boundary

- A user seen exactly `recently_seen_threshold` seconds ago now counts as recently seen, like in the
  `onlyRecentlySeen()` scope.
- A new timestamp is written as soon as `update_threshold` seconds have passed. 0.x waited until more than that.

Only tests that travel to exactly a threshold are affected. Change their expectation, or travel one second further.

## 9. Thresholds Must Be Integers of 0 or More

`update_threshold` and `recently_seen_threshold` throw an `InvalidArgumentException` unless they are integers of 0 or
more.

- Search for runtime config changes such as `config(['last-seen.update_threshold' => '60'])` or
  `Config::set('last-seen.recently_seen_threshold', ...)` and pass integers.
- Make sure the published `config/last-seen.php` still casts both values with `(int)`, like the shipped file.
- Check `LAST_SEEN_UPDATE_THRESHOLD` and `LAST_SEEN_RECENTLY_SEEN_THRESHOLD` in `.env`, `.env.example`,
  `.env.testing` and `phpunit.xml` for negative values. Tell the user to check deployed environments as well.

## 10. Index on `last_seen_at`

Already published migrations stay as they are. Do not edit them, and do not publish the migration again: the 1.0
migration stops with an error when `last_seen_at` already exists.

Check whether the users table has an index on `last_seen_at`, for example with `php artisan db:table users`. If not,
create a new migration for the table of the model in `config('last-seen.models.user')`:

```php
public function up(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->index('last_seen_at');
    });
}

public function down(): void
{
    Schema::table('users', function (Blueprint $table) {
        $table->dropIndex(['last_seen_at']);
    });
}
```

Run it only against a local database. Remind the user to run it when deploying.

## 11. Remove `LAST_SEEN_USER_MODEL`

The variable never had an effect: the user model is set in `config/last-seen.php` under `models.user`. Remove it
from `.env.example` and the local `.env`. If it named a different model than `models.user`, tell the user. Remind
the user to remove it from deployed environments.

## 12. Finish

- Run the test suite and any static analysis the project uses, and fix failures caused by the upgrade.
- Summarize for the user what you changed, which decisions are still open, and what has to happen outside the
  repository, such as environment variables on servers or running the new migration.
- Mention optional improvements, but do not apply them without asking: `LastSeen::trackUsing()` for opt-outs,
  `onlyRecentlySeen()` instead of hand-written cutoffs such as `where('last_seen_at', '>=', now()->subMinutes(5))`,
  and `LastSeen::forget()` to delete the timestamp.
