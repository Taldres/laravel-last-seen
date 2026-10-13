# Choosing Which Requests Count

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

Inertia marks prefetch requests with a `Purpose: prefetch` header. Do not leave those out either: when the user follows
a prefetched link while the page is still cached, Inertia shows it without another request, so the prefetch is the only
request the server sees for that visit. Inertia's polling, on the other hand, runs in the background and is not marked.
Give it a header of your own and leave those requests out:

```js
usePoll(30000, { only: ['notifications'], headers: { 'X-Background-Poll': '1' } })
```

```php
LastSeen::ignoreRequestsUsing(fn (Request $request): bool => $request->hasHeader('X-Background-Poll'));
```

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
