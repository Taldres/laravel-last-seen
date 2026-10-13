# Changelog

All notable changes to `laravel-last-seen` will be documented in this file

## v1.6.1 - 2026-10-13

<!-- Release notes generated using configuration in .github/release.yml at main -->
### What's Changed

#### Bug Fixes

* fix: reject invalid .env thresholds, keep newer parallel writes, carry the written user by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/36

#### Documentation

* docs: explain conflicts with long transactions on the users table by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/37

#### Behaviour to know about

- An invalid `LAST_SEEN_UPDATE_THRESHOLD` or `LAST_SEEN_RECENTLY_SEEN_THRESHOLD` in `.env`, for example `banana`, now throws an `InvalidArgumentException` instead of being read as `0`. An entry without a value now falls back to the default instead of `0`.
- A configuration file published before 1.6.1 still casts the `.env` values with `(int)` and keeps the old behaviour. Publish it again with `php artisan vendor:publish --tag=last-seen-config --force`, or replace the two threshold lines as shown in [Configuration](https://github.com/Taldres/laravel-last-seen/blob/main/docs/configuration.md).
- A `last_seen_at` in the future is only replaced by a model that read that value. A model loaded without `last_seen_at` writes again once the future value has passed.

**Full Changelog**: https://github.com/Taldres/laravel-last-seen/compare/v1.6.0...v1.6.1

## v1.6.0 - 2026-10-13

<!-- Release notes generated using configuration in .github/release.yml at main -->
### What's Changed

#### Enhancements

* feat(event): add the written timestamp to LastSeenRecordedEvent by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/32
* feat: show the package setup in php artisan about by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/35

#### Documentation

* docs: move the detailed documentation into docs/ by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/33
* docs: add recipes for inactive and returning users by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/34

**Full Changelog**: https://github.com/Taldres/laravel-last-seen/compare/v1.5.0...v1.6.0

## v1.5.0 - 2026-10-13

<!-- Release notes generated using configuration in .github/release.yml at main -->
### What's Changed

#### Enhancements

* feat(trait): add the lastSeenSince scope by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/30

#### Documentation

* docs(readme): explain why to use the package by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/29

**Full Changelog**: https://github.com/Taldres/laravel-last-seen/compare/v1.4.0...v1.5.0

## v1.4.0 - 2026-10-13

<!-- Release notes generated using configuration in .github/release.yml at main -->
### What's Changed

#### Enhancements

* feat(event): fire LastSeenRecordedEvent when last_seen_at is written by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/25
* feat(middleware): leave out requests with ignoreRequestsUsing() by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/28

#### Documentation

* docs: describe what the package measures and drop the online wording by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/27

#### Behaviour to know about

- A test that fully mocks the facade (`LastSeen::shouldReceive('shouldTrack')`) and sends a request through the middleware has to stub `shouldIgnoreRequest()` as well, or use `LastSeen::partialMock()` or `LastSeen::spy()`. The middleware reports the missing method, so the symptom is a `shouldTrack()` or `record()` expectation that was never met.
- `record()` and `updateLastSeenAt()` now fire a `LastSeenRecordedEvent`. With a full `Event::fake()`, `Event::assertNothingDispatched()` fails after a direct `$user->updateLastSeenAt()`. Fake only the events you assert on, or use `LastSeen::fake()`.
- `record()` now reads `recently_seen_threshold` before it writes, so an invalid value (for example a negative `LAST_SEEN_RECENTLY_SEEN_THRESHOLD`) stops the writes instead of only the reads.

**Full Changelog**: https://github.com/Taldres/laravel-last-seen/compare/v1.3.0...v1.4.0

## v1.3.0 - 2026-10-12

<!-- Release notes generated using configuration in .github/release.yml at main -->
### What's Changed

#### Enhancements

* feat(trait): add notRecentlySeen, neverSeen and lastSeenBefore scopes by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/20
* feat(config): default the user model to AUTH_MODEL by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/21

#### Documentation

* docs: add usage recipes and pitfalls to the README and the skill by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/22

#### Maintenance

* chore: ignore .claude and keep .env.example out of the dist archive by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/23
* ci: test against all database versions of laravel/framework by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/24

#### Other Changes

* docs: use the new banner in the README by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/19

**Full Changelog**: https://github.com/Taldres/laravel-last-seen/compare/v1.2.0...v1.3.0

## v1.2.0 - 2026-10-09

<!-- Release notes generated using configuration in .github/release.yml at main -->
### What's Changed

#### Enhancements

* feat(facade): add LastSeen::fake() for application tests by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/18

#### Other Changes

* docs: replace the README banner with the new GitHub banner by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/17

**Full Changelog**: https://github.com/Taldres/laravel-last-seen/compare/v1.1.0...v1.2.0

## v1.1.0 - 2026-10-06

<!-- Release notes generated using configuration in .github/release.yml at main -->
### What's Changed

#### Enhancements

* feat(boost): ship a skill for the upgrade from 0.x to 1.0 by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/15

**Full Changelog**: https://github.com/Taldres/laravel-last-seen/compare/v1.0.0...v1.1.0

## v1.0.0 - 2026-10-06

<!-- Release notes generated using configuration in .github/release.yml at main -->
### What's Changed

#### Breaking Changes

* feat!: prepare the 1.0.0 release by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/10
* build!: require PHP 8.3 by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/11

#### Enhancements

* feat: adopt the package skeleton tooling and ship a Boost skill by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/12

#### Bug Fixes

* fix: harden edge cases before 1.0 by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/14

#### Other Changes

* docs: readme banner by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/13

**Full Changelog**: https://github.com/Taldres/laravel-last-seen/compare/v0.4.0...v1.0.0

## v0.4.0 - 2026-04-02

### What's Changed

* chore(deps): bump actions/checkout from 4 to 5 by @dependabot[bot] in https://github.com/Taldres/laravel-last-seen/pull/5
* Add Laravel 13 and Pest 4 support by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/8
* Laravel 13 support by @Taldres in https://github.com/Taldres/laravel-last-seen/pull/9

**Full Changelog**: https://github.com/Taldres/laravel-last-seen/compare/v0.3.1...v0.4.0

## v0.3.1 - 2025-08-07

**Full Changelog**: https://github.com/Taldres/laravel-last-seen/compare/v0.3.0...v0.3.1

## 0.3.0 - 2025-08-07

### What's Changed

* Implementing new github actions for testing and release by @taldres
