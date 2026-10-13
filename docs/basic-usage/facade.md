# Facade

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
