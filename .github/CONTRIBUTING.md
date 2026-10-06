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

- Add or update tests for every change in behavior.
- Keep a pull request focused on one change.
- Describe user-facing changes in the pull request description; they end up in the release notes.

## Security Vulnerabilities

Please do not open a public issue. See [SECURITY](SECURITY.md) instead.
