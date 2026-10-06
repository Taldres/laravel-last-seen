# Contributing

Contributions are welcome: bug reports, fixes and documentation improvements alike.

## Setup

```bash
git clone https://github.com/Taldres/laravel-last-seen.git
cd laravel-last-seen
composer install
```

## Before Opening a Pull Request

Fix the code style, then run all checks. The CI runs the same checks and does not fix anything for you:

```bash
composer lint
composer test
```

`composer test` only checks and never changes files. Its steps can also be run on their own:

```bash
composer analyse     # static analysis with PHPStan
composer lint:check  # code style check with Pint
composer test:types  # type coverage
composer test:unit   # the Pest test suite
```

The test suite runs on an in-memory SQLite database. The CI also runs it against MySQL and PostgreSQL. To do the same
locally, point the `DB_*` variables at an empty database. The tests drop all of its tables:

```bash
DB_CONNECTION=pgsql DB_HOST=127.0.0.1 DB_PORT=5432 DB_DATABASE=testing DB_USERNAME=testing DB_PASSWORD=password composer test:unit
```

- Add or update tests for every change in behavior.
- Give test tables the column types a real application would use. SQLite accepts any value in any column and hides
  mismatches that MySQL and PostgreSQL reject.
- Keep a pull request focused on one change.
- Describe user-facing changes in the pull request description. The release notes are generated from pull request
  titles, grouped by the label a maintainer assigns: `breaking`, `enhancement`, `bug`, `documentation`,
  `dependencies` or `maintenance`. Pull requests labelled `skip-changelog` are left out.

## Security Vulnerabilities

Please do not open a public issue. See [SECURITY](SECURITY.md) instead.
