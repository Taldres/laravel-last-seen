## Laravel Last Seen

This application uses `taldres/laravel-last-seen`, which records the time of a user's last request in a `last_seen_at`
column. It knows when a user was last seen, not whether the user is present right now. Use the
`laravel-last-seen-development` skill before you write or change code that reads, writes, filters or deletes this
timestamp.
