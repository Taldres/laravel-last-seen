# Events

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

The event carries the `$user`, the `$previousLastSeenAt` the model held before the write, the `$recordedAt` value that
was written and `$becameRecentlySeen`.
`$becameRecentlySeen` is `true` when the user was not recently seen before the write, so `recentlySeen()` was `false`
and is `true` now. That includes users who were never seen and models that were just created. It says nothing about
whether the user is present right now, only that a new stretch of activity began. A previous value that cannot be read
is unknown: that is a model loaded without the `last_seen_at` column, or a cast that does not return a `Carbon`
instance. It is reported as `null` with `$becameRecentlySeen` set to `false`, so the package never claims a transition
it did not observe. A previous value in the future does not count. With a recently seen threshold below the update
threshold the flag is `true` for almost every write, because the previous value is always older than that threshold.

The previous value and the flag come from the model instance, not from the database, so they are only as fresh as that
instance. They can be wrong when the instance is stale: a user that your application serves from a cache, parallel
requests with an update threshold of `0`, `forgetLastSeenAt()` running at the same time as a request, or a retry on the
same instance after a write was rolled back. Treat `$becameRecentlySeen` as a hint and make listeners idempotent, for
example by deduplicating notifications.

- The user is already updated when the listener runs. A listener must not record the same user again: with an update
  threshold of `0` and a column that stores fractions of a second that recurses.
- If the key of the model was changed in memory, the row of the original key is written. The event then carries a copy
  of the model with the original key, so a queued listener loads the user whose row was written.
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

- `$recordedAt` holds the written value as a freshly loaded model reads it, in the precision of the stored value, so it
  compares equal to `last_seen_at` of the reloaded user until the next write. A queued listener reloads the user, whose
  `last_seen_at` may have moved on by then, while `$recordedAt` stays the value of this write. Together with the user's
  key it identifies the write, for example to deduplicate notifications. When you create the event yourself without it,
  it is the current time.
- Queued listeners store the user's key, `previousLastSeenAt` and `recordedAt` in the queue, see [Privacy](../privacy.md#deleting-the-timestamp). If
  a listener logs the event, it creates the activity history that the package avoids by itself.
- There is no event for users who stop being recently seen: nothing happens in the application when they stop sending
  requests. Use a scheduled job and the scopes for that, see
  [Inactive and Returning Users](../recipes/inactive-and-returning-users.md).

## Manually Dispatching the Event

You can also dispatch the `UserWasActiveEvent` from your own application code:

```php
use Taldres\LastSeen\Events\UserWasActiveEvent;

UserWasActiveEvent::dispatch($user);
```
