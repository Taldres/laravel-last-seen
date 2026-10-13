# Testing Your Application

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
