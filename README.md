![Laravel Last Seen — track users' last activity in Laravel](.github/assets/laravel-last-seen.png)

[![Packagist Version](https://img.shields.io/packagist/v/taldres/laravel-last-seen)](https://packagist.org/packages/taldres/laravel-last-seen)
![Tests](https://github.com/Taldres/laravel-last-seen/actions/workflows/tests.yml/badge.svg)

# Laravel Last Seen

A simple Laravel package to track a user's last seen and recently seen status. This package provides traits, middleware, events, and configuration to easily record and query when a user was last active in your Laravel application.

## Features

- Automatically update the `last_seen_at` timestamp for users
- Middleware to detect user activity
- Event-based architecture for extensibility
- Query scopes and helper methods to find users who were recently seen, never seen or last seen before a date
- Configurable thresholds for updating and checking activity
- Migration publishing for easy setup

## What It Measures

The package records the time of a user's last request. From that it tells you when a user was **last seen** and
whether they were **recently seen**, meaning within `LAST_SEEN_RECENTLY_SEEN_THRESHOLD` seconds.

It does not tell you whether a user is present right now. A user who closed the browser tab still counts as recently
seen until the threshold has passed, and a user who reads a page for ten minutes without sending a request does not.
For live presence, such as a list of who is viewing a page, use
[presence channels](https://laravel.com/docs/broadcasting#presence-channels).

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

## Installation

1. Install the package via Composer:

    ```bash
    composer require taldres/laravel-last-seen
    ```

2. Publish the migration:

    ```bash
    php artisan vendor:publish --tag=last-seen-migrations
    ```

   The migration finds your users table through the `models.user` setting. You only need to publish the configuration
   file to change it or other settings, see [Configuration](#configuration).

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

## Configuration

If necessary or in case of a newer version, you can publish the configuration file to customize the package settings:

```bash
php artisan vendor:publish --provider="Taldres\LastSeen\LastSeenServiceProvider" --tag="config"

```
or this command to force overwrite the existing configuration file:

```bash
php artisan vendor:publish --provider="Taldres\LastSeen\LastSeenServiceProvider" --tag="config" --force
```

Run `php artisan config:clear` afterwards if your configuration is cached.

---

In the `config/last-seen.php` file, you can specify the User model:

- `models.user`: The fully qualified class name of the User model. The migration uses it to find the users table. It
  defaults to the `AUTH_MODEL` environment variable that Laravel's own auth configuration reads, or `App\Models\User`.

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
  routes that should not count as activity, or leave requests out with a callback, see
  [Choosing Which Requests Count](#choosing-which-requests-count).
- For each such request of a tracked user the middleware fires a `UserWasActiveEvent`. The event means "activity
  detected", not "timestamp written": `last_seen_at` is only written once `LAST_SEEN_UPDATE_THRESHOLD` seconds have
  passed since the stored value, so it can lag behind the latest activity by up to that many seconds. Parallel requests
  write it only once. Each write fires a `LastSeenRecordedEvent`, see [Events](#events).
- The middleware records the user that is authenticated once the request has been handled: the user of the default
  guard, or of the guard an `auth:<guard>` middleware selected. Logout requests are therefore not recorded, and when a
  request switches users, for example while an admin impersonates someone, only the final user is.
- Error responses count as activity too. Registering the middleware twice, for example globally and on a route, still
  records each request once.
- If resolving the user or recording fails, for example because of a database error or an exception in your
  `trackUsing()` or `ignoreRequestsUsing()` callback or one of your listeners, the exception is reported and the
  response is returned unchanged. A request whose `ignoreRequestsUsing()` callback throws is not recorded.
- The update threshold is checked in memory first, so a request within the threshold runs no query for the write. A due
  write is a single `UPDATE` by primary key that only changes the row if the stored value is still older than the
  threshold, which is why parallel requests write once.
- The `trackUsing()` callback runs for every request of an authenticated user, and once more when a write is due. Keep it
  free of queries, for example by reading an attribute of the user instead of loading a relation.

### Choosing Which Requests Count

Requests that a page sends in the background count as activity too. A single-page app that polls for notifications every
30 seconds keeps `last_seen_at` fresh long after the user stopped working. Register a callback in a service provider to
leave such requests out. It returns `true` for a request that does not count:

```php
use Illuminate\Http\Request;
use Taldres\LastSeen\Facades\LastSeen;

public function boot(): void
{
    LastSeen::ignoreRequestsUsing(
        fn (Request $request): bool => $request->is('api/notifications/poll', 'api/health'),
    );
}
```

- The callback runs after the request has been handled, so `$request->route()` is available, or `null` for a request
  that matched no route. It runs before the middleware resolves the user: for an ignored request the middleware skips
  that, the `trackUsing()` call and the `UserWasActiveEvent`. A route behind `auth` has resolved the user already.
- It runs for every request the middleware handles, guests included, so keep it cheap. If `last_seen_at` feeds
  decisions of your application, base it on facts the server controls rather than on headers a client can set.
- There is one callback. A second call to `ignoreRequestsUsing()` replaces the first, so combine all conditions in a
  single callback.
- Register it once while booting. It stays active for all later requests, also in Octane or queue workers.
- It only concerns the middleware. `updateLastSeenAt()`, `LastSeen::record()` and a `UserWasActiveEvent` that you
  dispatch yourself still record.

A request filter fits endpoints that only run in the background. Livewire sends polls and user actions to the same
update route with the same headers, and it can bundle the updates of several components into one request, for example
those of a parent and its bound children. A filter on that route or header would therefore also drop real activity. Use
a dedicated endpoint, or a marker that your application adds to background requests.

Requests that an integration makes with an API token are activity of that integration, not of the user. If your
integrations use tokens of their own, leave them out, for example by the name of the token. Token requests of a mobile
app stay counted. Check the token itself. With Laravel Sanctum, `tokenCan()` is not enough: requests that authenticate
with the session cookie of a single-page app carry a transient token that has every ability, and so does a token
created with the default `*` ability.

```php
use Laravel\Sanctum\PersonalAccessToken;

LastSeen::ignoreRequestsUsing(function (Request $request): bool {
    $token = $request->user()?->currentAccessToken();

    return $token instanceof PersonalAccessToken && $token->name === 'integration';
});
```

The opposite problem is a user who works in the browser without sending requests and drops out of "recently seen". Let
the front end call a route that only carries the middleware, from user interactions rather than from a plain timer:

```php
Route::post('/activity', fn () => response()->noContent())
    ->middleware(['auth:sanctum', \Taldres\LastSeen\Middleware\UpdateLastSeenMiddleware::class]);
```

### Checking Activity

- `$user->recentlySeen()`: Returns `true` if `last_seen_at` is at most `LAST_SEEN_RECENTLY_SEEN_THRESHOLD` seconds ago.
- `User::onlyRecentlySeen()`: Query scope to get only recently seen users, using the same rule as `recentlySeen()`.
- `User::notRecentlySeen()`: The exact opposite of `onlyRecentlySeen()`: everyone who is not recently seen, including
  users who were never seen.
- `User::neverSeen()`: Query scope for users whose `last_seen_at` is `null`.
- `User::lastSeenBefore($moment)`: Query scope for users last seen before a date, for example
  `User::lastSeenBefore(now()->subDays(30))`. Users who were never seen are not included, so combine it with
  `neverSeen()` to find everyone who has been inactive for a while:

    ```php
    User::query()->where(fn ($query) => $query
        ->neverSeen()
        ->orWhere->lastSeenBefore(now()->subDays(30)));
    ```

All scopes compare in the date format and cast of your model, and `lastSeenBefore()` converts the moment to the
application timezone first, so a `Carbon` instance in another timezone works as expected.

A moment is compared in the precision of the stored column. With whole seconds, `lastSeenBefore(now())` therefore does
not include a user who was recorded within the current second.

A `last_seen_at` in the future, e.g. caused by clock drift between servers, counts as recently seen until the next
recorded activity replaces it.

Keep `app.timezone` on UTC. With another timezone, `last_seen_at` is stored as local time, which is ambiguous for an hour
when daylight saving time ends, just like Laravel's own timestamps.

`last_seen_at` can lag behind the latest activity by up to `LAST_SEEN_UPDATE_THRESHOLD` seconds. Keep
`LAST_SEEN_RECENTLY_SEEN_THRESHOLD` above it, otherwise an active user is not recently seen between two writes.

`recentlySeen()` reads the `last_seen_at` attribute, so include it when you load users with `select()`. Without it the
call returns `false`, or throws a `MissingAttributeException` when `Model::preventAccessingMissingAttributes()` is
enabled, as it is by `Model::shouldBeStrict()`.

### Showing Recent Activity

```php
$recentlyActive = User::onlyRecentlySeen()->count();

$users = User::onlyRecentlySeen()->latest('last_seen_at')->get();
```

```blade
@if ($user->recentlySeen())
    Recently active
@else
    Last seen {{ $user->last_seen_at?->diffForHumans() ?? 'never' }}
@endif
```

Who may see this status is up to your application, see [Privacy](#privacy).

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

LastSeen::record($user);                  // same as $user->updateLastSeenAt()
LastSeen::forget($user);                  // same as $user->forgetLastSeenAt()
LastSeen::recentlySeen($user);            // same as $user->recentlySeen()
LastSeen::recentlySeenSince();            // earliest last_seen_at that still counts as recently seen
LastSeen::shouldTrack($user);             // whether last_seen_at may be written for this user
LastSeen::trackUsing($callback);          // decide per user whether to track, see Privacy
LastSeen::ignoreRequestsUsing($callback); // leave out requests that do not count, see Choosing Which Requests Count
LastSeen::shouldIgnoreRequest($request);  // whether that callback leaves out the request
```

The facade is also registered as the global alias `LastSeen`. It resolves `Taldres\LastSeen\LastSeenManager` from
the container, so you can also inject the manager directly. The trait, the middleware and the event listener all use it, so the same rules apply everywhere.

### Events

The package fires two events:

- `UserWasActiveEvent` whenever activity of a tracked user is detected, which is every request that passes the
  middleware. Recording the activity never fires it again.
- `LastSeenRecordedEvent` whenever `last_seen_at` was actually written, no matter whether the middleware, `$user->updateLastSeenAt()`
  or `LastSeen::record()` did it. That is about once per user and `LAST_SEEN_UPDATE_THRESHOLD` seconds, so it is the
  event to use for work that should not run on every request.

```php
use Taldres\LastSeen\Events\LastSeenRecordedEvent;

Event::listen(function (LastSeenRecordedEvent $event) {
    if ($event->becameRecentlySeen) {
        // $event->user was not recently seen before this write.
    }
});
```

The event carries the `$user`, the `$previousLastSeenAt` the model held before the write and `$becameRecentlySeen`.
`$becameRecentlySeen` is `true` when the user was not recently seen before the write, so `recentlySeen()` was `false`
and is `true` now. That includes users who were never seen and models that were just created. It says nothing about
whether the user is present right now, only that a new stretch of activity began. A previous value that cannot be read
is unknown: that is a model loaded without the `last_seen_at` column, or a cast that does not return a `Carbon`
instance. It is reported as `null` with `$becameRecentlySeen` set to `false`, so the package never claims a transition
it did not observe. A previous value in the future does not count. With a recently seen threshold below the update
threshold the flag is `true` for almost every write, because the previous value is always older than that threshold.

Both values come from the model instance, not from the database, so they are only as fresh as that instance. They can
be wrong when the instance is stale: a user that your application serves from a cache, parallel requests with an update
threshold of `0`, `forgetLastSeenAt()` running at the same time as a request, or a retry on the same instance after a
write was rolled back. Treat `$becameRecentlySeen` as a hint and make listeners idempotent, for example by deduplicating
notifications.

- The user is already updated when the listener runs. A listener must not record the same user again: with an update
  threshold of `0` and a column that stores fractions of a second that recurses.
- The event implements `ShouldDispatchAfterCommit`, so listeners for a write that is rolled back are not called. With
  several database connections the framework attaches the event to the last open transaction of any connection, so the
  rollback of an unrelated transaction can drop it. After a rollback the model instance still holds the new value.
- A listener that throws makes `record()` and `updateLastSeenAt()` throw after the row was written, and the middleware
  reports such exceptions without changing the response. Inside an open transaction the listeners run when it commits.
  An exception then comes out of the commit instead of `record()`, can skip later `DB::afterCommit()` callbacks and is
  not caught by the middleware, so it can turn the response into an error when the transaction wraps the request. Use
  queued listeners for side effects, and let them decide whether to queue at all, for example to queue only when the
  user became recently seen:

    ```php
    public function shouldQueue(LastSeenRecordedEvent $event): bool
    {
        return $event->becameRecentlySeen;
    }
    ```

- Queued listeners store the user's key and `previousLastSeenAt` in the queue, see [Privacy](#privacy). If a listener
  logs the event, it creates the activity history that the package avoids by itself.
- There is no event for users who stop being recently seen: nothing happens in the application when they stop sending
  requests. Use a scheduled job and the `notRecentlySeen()` scope for that.

### Testing Your Application

`LastSeen::fake()` replaces the manager with a fake for the rest of the test. The middleware, the event listener and
the trait then write nothing, and you can assert which users were recorded or forgotten:

```php
use Taldres\LastSeen\Facades\LastSeen;

LastSeen::fake();

$this->actingAs($user)->get('/dashboard');

LastSeen::assertRecorded($user);
LastSeen::assertNotRecorded($otherUser);
```

The fake offers `assertRecorded()`, `assertRecordedTimes()`, `assertNotRecorded()`, `assertNothingRecorded()`,
`assertForgotten()`, `assertNotForgotten()` and `assertNothingForgotten()`. Instead of a model, the assertions that
take a user also accept a callback that receives the model, for example
`LastSeen::assertRecorded(fn (User $recorded) => $recorded->is($user))`. `LastSeen::recorded()` and
`LastSeen::forgotten()` return the matching users. The fake keeps the configuration and your `trackUsing()` and `ignoreRequestsUsing()` callbacks, so
users that would not be tracked and requests that are left out are not recorded. It ignores the update threshold, so every call counts.

- Use `Event::fake([UserWasActiveEvent::class])` to assert that activity was detected without writing anything.
- Use `Event::fake([LastSeenRecordedEvent::class])` to assert that a write happened. The row is still written. With a
  full `Event::fake()`, `Event::assertNothingDispatched()` fails after a direct `$user->updateLastSeenAt()`.
- `LastSeen::fake()` writes nothing and fires no `LastSeenRecordedEvent`. Dispatch the event yourself to test your
  listeners.
- Disable tracking with `config(['last-seen.enabled' => false])` or `LastSeen::trackUsing(fn () => false)`.
- When mocking the facade, use `LastSeen::partialMock()`, or stub both `shouldIgnoreRequest()` and `shouldTrack()`: the
  middleware asks the first one for every request, and a full mock does not know the method you did not stub: the
  middleware reports that exception, so the symptom is a `shouldTrack()` or `record()` expectation that was never met. A
  `LastSeen::spy()` returns `false` from both, so the middleware never calls `record()`.

### Manually Dispatching the Event

You can also dispatch the `UserWasActiveEvent` from your own application code:

```php
use Taldres\LastSeen\Events\UserWasActiveEvent;
use Illuminate\Support\Facades\Event;

Event::dispatch(new UserWasActiveEvent($user));
```

### Several User Models

The trait works on every Eloquent model with a `last_seen_at` column, but the published migration only adds the column to
the table of `models.user`. For another model, for example an `Admin`, add the column with a migration of your own and use
the trait on that model:

```php
Schema::table('admins', function (Blueprint $table) {
    $table->timestamp('last_seen_at')->nullable()->index();
});
```

The middleware records the user of the guard that is authenticated once the request has been handled, so put it on the
routes that use `auth:admin`, or in the middleware group of the application. The `trackUsing()` callback receives every
tracked model, so type-hint `Model` instead of a single class.

## AI Agents

The package ships two [Laravel Boost](https://laravel.com/framework/docs/boost) skills for coding agents:

- `laravel-last-seen-development` teaches agents how to use the package.
- `upgrade-laravel-last-seen-v1` walks agents through the upgrade from 0.4 to 1.0.

Boost offers to install them when you run `php artisan boost:install`, or `php artisan boost:update --discover` in an
existing setup.

## Privacy

`last_seen_at` is tied to a user, so it is personal data. Your application decides the purpose, the legal basis, how
users are informed and how long the data is kept. The package cannot make an application compliant on its own, but it
keeps the data small and under your control:

- It stores a single `last_seen_at` column on your users table. Each write overwrites the previous value. There is no
  activity history, and no IP address or user agent is stored.
- It does not send any data to external services.
- Who may see another user's activity status is up to your application, for example through policies.
- Listeners of the `LastSeenRecordedEvent` receive the user and the previous `last_seen_at`. What they do with it, such
  as logging it or queueing it, is up to your application, see [Deleting the Timestamp](#deleting-the-timestamp).

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
`last_seen_at` untouched and fires no `LastSeenRecordedEvent`. The callback receives every model that uses the trait, so type-hint `Model` if more than one
of your models does. Register it once while booting: it stays active for all later requests, also in Octane or queue
workers.

The callback also lets you skip requests that do not reflect the user's own activity. While a support agent
impersonates a user, for example, their requests would otherwise keep the user "recently seen". Check whatever marks an
impersonation in your application, such as a session key:

```php
LastSeen::trackUsing(fn (User $user): bool => ! session()->has('impersonator_id'));
```

### Deleting the Timestamp

- `$user->forgetLastSeenAt()` sets `last_seen_at` to `null`. Combine it with an opt-out, otherwise the next request
  writes it again. It throws a `LogicException` for a model that was loaded without its primary key.
- When a user is deleted, `last_seen_at` is deleted with the row.
- `LAST_SEEN_ENABLED=false` only stops new writes. It does not delete stored values.
- A queued listener of the `LastSeenRecordedEvent` copies the user's key and `previousLastSeenAt` into the `jobs` table,
  and into `failed_jobs` when it fails. Neither `forgetLastSeenAt()` nor deleting the user removes those copies. Add
  `public $deleteWhenMissingModels = true;` to the listener so jobs for deleted users are dropped instead of failing,
  and prune failed jobs. Tools that record events, such as Telescope's event watcher, store the payload as well.

## Upgrading

See [UPGRADE.md](UPGRADE.md) for the changes between major versions, including the upgrade from 0.4 to 1.0. It also
explains how to let a coding agent do the upgrade with the `upgrade-laravel-last-seen-v1` skill.

## Contributing

See [CONTRIBUTING](.github/CONTRIBUTING.md). Please report security vulnerabilities as described in
[SECURITY](.github/SECURITY.md) instead of opening a public issue.

## License

MIT
