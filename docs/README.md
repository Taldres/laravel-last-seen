# Laravel Last Seen Documentation

- [Introduction](introduction.md): what the package measures and why to use it
- [Installation](installation.md): requirements, supported databases and installation steps
- [Configuration](configuration.md): the user model, enabling the package and the thresholds

## Basic Usage

- [How Activity Is Tracked](basic-usage/tracking-activity.md): what the middleware records, when it writes, and long
  transactions on the users table
- [Checking Activity](basic-usage/checking-activity.md): `recentlySeen()`, the query scopes and showing recent activity
- [Updating Activity](basic-usage/updating-activity.md): `updateLastSeenAt()` and `forgetLastSeenAt()`
- [Facade](basic-usage/facade.md): the `LastSeen` facade and the manager behind it

## Advanced Usage

- [Choosing Which Requests Count](advanced-usage/choosing-which-requests-count.md): leaving out background requests
  and API integrations, and recording activity without requests
- [Events](advanced-usage/events.md): `UserWasActiveEvent` and `LastSeenRecordedEvent`
- [Several User Models](advanced-usage/several-user-models.md): tracking more than one model
- [Testing Your Application](advanced-usage/testing.md): `LastSeen::fake()`, event fakes and facade mocks

## Recipes

- [Inactive and Returning Users](recipes/inactive-and-returning-users.md): reminding inactive users once per inactive
  period and reacting when a user comes back

## Privacy

- [Privacy](privacy.md): the stored data, opting users out and deleting the timestamp

## More

- [AI Agents](ai-agents.md): the Laravel Boost skills that ship with the package
- [Upgrade Guide](../UPGRADE.md): changes between major versions
