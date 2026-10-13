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

Both thresholds must be integers of 0 or more. Other values throw an `InvalidArgumentException`.

Each setting has a default value, so you only need to override them if you want to change the default behavior.
