# Inactive and Returning Users

To remind users who have been inactive for a while, run a scheduled job that looks for them. Store when a user was
reminded in a column of your own, for example a nullable `inactivity_reminded_at` timestamp on the users table, and only
remind users who were seen again since their last reminder. Each user is then reminded once per inactive period:

```php
// routes/console.php
use App\Models\User;
use App\Notifications\InactivityReminder;
use Illuminate\Support\Facades\Schedule;

Schedule::call(function () {
    User::query()
        ->lastSeenBefore(now()->subDays(30))
        ->where(fn ($query) => $query
            ->whereNull('inactivity_reminded_at')
            ->orWhereColumn('inactivity_reminded_at', '<', 'last_seen_at'))
        ->lazyById()
        ->each(function (User $user) {
            $user->notify(new InactivityReminder);
            $user->forceFill(['inactivity_reminded_at' => now()])->save();
        });
})->daily()->name('inactivity-reminders')->onOneServer();
```

- `lazyById()` pages by key, so writing the reminder column while iterating does not skip users. `chunk()` and `each()`
  on the query page by offset and would skip users that leave the result.
- Users who were never seen are not included. Add them with `neverSeen()` if they should be reminded too, see
  [Checking Activity](../basic-usage/checking-activity.md).
- `onOneServer()` needs a cache driver that supports atomic locks and, for a closure, a `name()` before it.

To react when a user comes back after a long break, listen to the `LastSeenRecordedEvent` and look at the previous
value. `$recordedAt` identifies the write, so a cache key built from it lets the listener act once per write, even if
the same event is handled twice, for example by a listener that is registered twice or a job that is retried after a
worker timeout:

```php
use App\Notifications\WelcomeBack;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Cache;
use Taldres\LastSeen\Events\LastSeenRecordedEvent;

class SendWelcomeBack implements ShouldQueue
{
    public $deleteWhenMissingModels = true;

    public function shouldQueue(LastSeenRecordedEvent $event): bool
    {
        return $event->previousLastSeenAt?->lt($event->recordedAt->copy()->subDays(30)) ?? false;
    }

    public function handle(LastSeenRecordedEvent $event): void
    {
        $key = "welcome-back:{$event->user->getKey()}:{$event->recordedAt->getTimestamp()}";

        if (Cache::add($key, true, now()->addDay())) {
            $event->user->notify(new WelcomeBack);
        }
    }
}
```

The previous value is `null` for users who were never seen and when it is unknown, so they get no message. The key is
written before the notification is sent, so a retry after a failed send does not send it again. If a duplicate is
better than a missing message, check the key with `Cache::has()` and write it after sending instead.
