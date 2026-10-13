# Checking Activity

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

- `User::lastSeenSince($moment)`: Query scope for users last seen at or after a date, when you need a window other than
  the recently seen threshold, for example `User::lastSeenSince(now()->subMinutes(15))`. Users who were never seen are
  not included. For the same moment, `lastSeenSince()` and `lastSeenBefore()` split all users who were seen into two
  groups without a gap or an overlap. A window shorter than `LAST_SEEN_UPDATE_THRESHOLD` seconds is not reliable, see
  below.

All scopes compare in the date format and cast of your model. `lastSeenBefore()` and `lastSeenSince()` convert the
moment to the application timezone first, so a `Carbon` instance in another timezone works as expected.

A moment is compared in the precision of the stored column. With whole seconds, `lastSeenBefore(now())` therefore does
not include a user who was recorded within the current second, while `lastSeenSince(now())` does.

A `last_seen_at` in the future, e.g. caused by clock drift between servers, counts as recently seen until the next
recorded activity replaces it. Only a model that read that future value replaces it, so a newer value that a parallel
request wrote is never overwritten. A model loaded without `last_seen_at` writes again once the future value has
passed.

Keep `app.timezone` on UTC. With another timezone, `last_seen_at` is stored as local time, which is ambiguous for an hour
when daylight saving time ends, just like Laravel's own timestamps.

`last_seen_at` can lag behind the latest activity by up to `LAST_SEEN_UPDATE_THRESHOLD` seconds. Keep
`LAST_SEEN_RECENTLY_SEEN_THRESHOLD` and the windows you pass to `lastSeenSince()` above it, otherwise an active user is
not seen within the window between two writes.

`recentlySeen()` reads the `last_seen_at` attribute, so include it when you load users with `select()`. Without it the
call returns `false`, or throws a `MissingAttributeException` when `Model::preventAccessingMissingAttributes()` is
enabled, as it is by `Model::shouldBeStrict()`.

## Showing Recent Activity

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

Who may see this status is up to your application, see [Privacy](../privacy.md).
