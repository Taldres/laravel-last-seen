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
