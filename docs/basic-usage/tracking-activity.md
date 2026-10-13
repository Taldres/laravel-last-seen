# How Activity Is Tracked

- Every request that passes through the middleware with an authenticated user counts as activity. This includes
  background requests such as polling, which can keep an otherwise idle user "recently seen". Leave the middleware off
  routes that should not count as activity, or leave requests out with a callback, see
  [Choosing Which Requests Count](../advanced-usage/choosing-which-requests-count.md).
- For each such request of a tracked user the middleware fires a `UserWasActiveEvent`. The event means "activity
  detected", not "timestamp written": `last_seen_at` is only written once `LAST_SEEN_UPDATE_THRESHOLD` seconds have
  passed since the stored value, so it can lag behind the latest activity by up to that many seconds. Parallel requests
  write it only once. Each write fires a `LastSeenRecordedEvent`, see [Events](../advanced-usage/events.md).
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

## Long Transactions on the Users Table

A due write is a single `UPDATE` without a transaction of its own. If your application wraps the request in a
transaction, the write becomes part of it. The middleware runs it after your controller and before the response is
returned, so the request waits for it.

Because it changes the users row, it can collide with a long transaction of your application that first reads and
later writes the same users row, for example a queued job that recalculates a user's totals:

- On MariaDB 11.6 and later, `innodb_snapshot_isolation` is on by default. When `last_seen_at` was written after the
  transaction's first read, its own `UPDATE` of the users row fails with
  `1020 Record has changed since last read in table 'users'`. Without that setting, a value the transaction computed
  from its earlier reads can silently overwrite a newer one.
- Any other write to the users row has the same effect. The package only makes such writes more frequent.

Ways to handle it in your application:

- Let Laravel retry the transaction: `DB::transaction(fn () => ..., attempts: 3)`. Laravel retries on this error for
  the outermost transaction. A nested transaction throws a `DeadlockException` instead.
- Lock the users row with `lockForUpdate()` as the first statement of the transaction. The transaction then succeeds,
  but the `last_seen_at` write waits until it commits, and so does the user's request, up to
  `innodb_lock_wait_timeout` (50 seconds by default). Keep such transactions short.
- Run the transaction with `READ COMMITTED`, or move the writes to the users row into a short transaction of their own
  at the end, if the calculation does not need one consistent snapshot of all its reads.
- Atomic updates such as `$user->increment('points')` prevent lost updates, but not the error above.

