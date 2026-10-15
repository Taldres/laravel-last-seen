# Privacy

`last_seen_at` is tied to a user, so it is personal data. Your application decides the purpose, the legal basis, how
users are informed and how long the data is kept. The package cannot make an application compliant on its own, but it
keeps the data small and under your control:

- It stores a single `last_seen_at` column on your users table. Each write overwrites the previous value. There is no
  activity history, and no IP address or user agent is stored.
- It does not send any data to external services.
- Who may see another user's activity status is up to your application, for example through policies.
- Listeners of the `LastSeenRecordedEvent` receive the user, the previous and the written `last_seen_at`. What they do
  with it, such as logging it or queueing it, is up to your application, see
  [Deleting the Timestamp](#deleting-the-timestamp).

## Opting Users Out

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

## Deleting the Timestamp

- `$user->forgetLastSeenAt()` sets `last_seen_at` to `null`. Combine it with an opt-out, otherwise the next request
  writes it again. It throws a `LogicException` for a model that was loaded without its primary key.
- When a user is deleted, `last_seen_at` is deleted with the row.
- `LAST_SEEN_ENABLED=false` only stops new writes. It does not delete stored values.
- A queued listener of the `LastSeenRecordedEvent` copies the user's key, `previousLastSeenAt` and `recordedAt` into the
  `jobs` table, and into `failed_jobs` when it fails. Neither `forgetLastSeenAt()` nor deleting the user removes those
  copies. Add `public $deleteWhenMissingModels = true;` to the listener so jobs for deleted users are dropped instead of
  failing, and prune failed jobs. Tools that record events, such as Telescope's event watcher, store the payload as
  well.

## Forgetting Old Values

To keep the data only as long as you need it, forget the values of users who were last seen long ago:

```bash
php artisan last-seen:forget --days=90
php artisan last-seen:forget --months=6
php artisan last-seen:forget --years=1 --months=6
```

It sets `last_seen_at` to `null` for every user whose value is older than the given age. Users who were
never seen are not touched, and neither are the rows themselves, so this is not a replacement for Laravel's
`model:prune`, which deletes models.

The age is given in `--days` (up to 365), `--months` (up to 120) or `--years` (up to 100), alone or combined, so you
pick the unit that fits. At least one of them must be above 0.

- `--model=` selects another model that uses the trait. The default is the model of `models.user`.
- `--pretend` only reports how many values would be forgotten.
- Soft deleted rows are included. `updated_at` and other attributes stay as they are, and no events are fired.
- Global scopes are ignored, so soft deleted rows and rows of other tenants are included.
- It runs as a single `UPDATE` statement, so run it when little is going on if the table is large. It also works while
  `LAST_SEEN_ENABLED` is `false`, and it is only registered in console contexts, such as Artisan and the scheduler.
- Copies in the queue or in logs are not affected, see [Deleting the Timestamp](#deleting-the-timestamp).

Run it on a schedule, for example in `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('last-seen:forget --days=90')->daily();
```
