# Configuration

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

Both thresholds must be integers of 0 or more. Other values throw an `InvalidArgumentException`, also when they come
from the `.env` file: `LAST_SEEN_UPDATE_THRESHOLD=banana` is rejected instead of being read as `0`. An entry without a
value falls back to the default.

If you published the configuration file before version 1.6.1, it still casts the `.env` values with `(int)`, which turns
an invalid value into `0`. Publish it again with `--force`, or replace the casts with the calls from the current file:

```php
'update_threshold' => LastSeenDefaultThreshold::Update->fromEnv(env('LAST_SEEN_UPDATE_THRESHOLD')),
'recently_seen_threshold' => LastSeenDefaultThreshold::RecentlySeen->fromEnv(env('LAST_SEEN_RECENTLY_SEEN_THRESHOLD')),
```

Each setting has a default value, so you only need to override them if you want to change the default behavior.

## Checking the Configuration

`php artisan about --only=last_seen` shows the settings the package runs with: the package version, whether it is
enabled, the user model, whether that model uses the `LastSeen` trait, and both thresholds. It marks these problems:

- `MISSING`: the user model does not use the trait, so the middleware records nothing for it. Only the model in
  `models.user` is checked. If you track other models instead, see
  [Several User Models](advanced-usage/several-user-models.md).
- `MODEL NOT FOUND`: `models.user` does not name a class that can be loaded.
- `NOT A CLASS NAME`: `models.user` is not a string.
- `INVALID`: a threshold is not an integer of 0 or more, so recording throws an `InvalidArgumentException`.
- `not above the update threshold`: `last_seen_at` can lag behind the latest activity by up to the update threshold, so
  an active user can drop out of "recently seen" between two writes, see
  [Checking Activity](basic-usage/checking-activity.md).

Add `--json` for output a script can read. It holds the raw configuration values, and `user_model_trait` is `true`,
`false`, or `null` when the model cannot be loaded.
