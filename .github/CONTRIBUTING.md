# Contributing

Contributions are welcome: bug reports, fixes and documentation improvements alike.

## Setup

```bash
git clone https://github.com/Taldres/laravel-last-seen.git
cd laravel-last-seen
composer install
```

## Before Opening a Pull Request

Run the full check suite. It fixes the code style with Pint, then runs Pest and PHPStan:

```bash
composer test
```

The steps can also be run on their own:

```bash
composer pint       # fix code style
composer pest       # run the tests
composer test-stan  # run static analysis
```

- Add or update tests for every change in behavior.
- Keep a pull request focused on one change.
- Describe user-facing changes in the pull request description; they end up in the release notes.

## Security Vulnerabilities

Please do not open a public issue. See [SECURITY](SECURITY.md) instead.
