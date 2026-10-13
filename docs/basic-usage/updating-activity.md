# Updating Activity

- `$user->updateLastSeenAt()`: Writes `last_seen_at` if the user is tracked and the configured update threshold has
  passed, and returns whether the stored timestamp changed. With an update threshold of `0` it writes on every call
  unless `last_seen_at` already holds the current time, for example within the same second with the default date
  format. Only `last_seen_at` is written: no model events are fired, the model's `updated_at` timestamp is left
  untouched and other unsaved changes on the model are not persisted.
- `$user->forgetLastSeenAt()`: Sets `last_seen_at` to `null`, again without touching `updated_at` or other unsaved
  changes. It works even when the package is disabled.

`last_seen_at` is not added to your model's `$fillable`. If you need to mass-assign it, add it there yourself or use `forceFill()`.
