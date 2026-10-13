# Introduction

A simple Laravel package to track a user's last seen and recently seen status. This package provides traits, middleware, events, and configuration to easily record and query when a user was last active in your Laravel application.

```php
User::onlyRecentlySeen()->count();                // how many users were seen in the last few minutes
User::lastSeenBefore(now()->subDays(30))->get();  // who was last seen more than a month ago
$user->last_seen_at?->diffForHumans() ?? 'never'; // when a user was last seen
```

## Features

- Automatically update the `last_seen_at` timestamp for users
- Middleware to detect user activity
- Event-based architecture for extensibility
- Query scopes and helper methods to find users who were recently seen, never seen or last seen before a date
- Configurable thresholds for updating and checking activity
- Leave out background requests and let users opt out
- A fake with assertions for your own tests
- Migration publishing for easy setup

## What It Measures

The package records the time of a user's last request. From that it tells you when a user was **last seen** and
whether they were **recently seen**, meaning within `LAST_SEEN_RECENTLY_SEEN_THRESHOLD` seconds.

It does not tell you whether a user is present right now. A user who closed the browser tab still counts as recently
seen until the threshold has passed, and a user who reads a page for ten minutes without sending a request does not.
For live presence, such as a list of who is viewing a page, use
[presence channels](https://laravel.com/docs/broadcasting#presence-channels).

## Why Use This Package?

Updating a timestamp is easy. Handling concurrent requests, limiting writes, and keeping Eloquent timestamps and model
events untouched takes more care. This package handles those details and adds request filtering, per-user opt-outs,
query scopes, and a fake for your tests.

- **Throttled, conditional writes.** `last_seen_at` is written at most once per `LAST_SEEN_UPDATE_THRESHOLD` seconds
  with a single conditional `UPDATE`, so parallel requests of one user write once.
- **Nothing else changes.** `updated_at`, model events and unsaved attributes stay untouched.
- **You decide what counts.** Leave out background requests with `LastSeen::ignoreRequestsUsing()` and let users opt
  out with `LastSeen::trackUsing()`.
- **Ready for queries and tests.** Scopes such as `onlyRecentlySeen()` and `lastSeenBefore()`, and `LastSeen::fake()`
  for your own tests.
