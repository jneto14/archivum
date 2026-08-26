# Contributing to Archivum

Thanks for your interest in contributing to Archivum.

GitHub is the public collaboration platform for this project. You do not need
access to any internal tooling to contribute.

## Ways to Contribute

- **Bugs**: open a [bug report](../../issues/new?template=bug.yml).
- **Feature ideas**: open a [feature request](../../issues/new?template=feature.yml)
  or start a [Discussion](../../discussions) if the idea needs more back and
  forth first.
- **Documentation**: open a
  [documentation issue](../../issues/new?template=documentation.yml) or submit
  a PR directly for small fixes.
- **Code**: pick up an issue labeled
  [`good first issue`](../../labels/good%20first%20issue) or
  [`help wanted`](../../labels/help%20wanted), or propose your own change via a
  Discussion first if it's a larger piece of work.

## Development Setup

Requirements:

```text
PHP (matching Laravel 13 requirements)
Composer
Node.js + npm
MySQL
```

Setup:

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
php artisan migrate
npm run build
php artisan serve
```

Run the test suite and static analysis before opening a PR:

```bash
composer test      # Pest
composer analyse    # Larastan
composer format     # Pint
```

(Exact composer script names will be finalized once the project scaffold
lands — see the corresponding YouTrack task.)

## Development Philosophy

Please read the "Development Philosophy" and "Implementation Priorities"
sections of the [README](README.md) before making architectural changes.
Archivum favours simple, explicit, testable Laravel code over premature
abstraction (no repositories, generic DTOs, or services without a real
architectural reason).

## Pull Requests

- Explain **what** changed and **why**.
- Reference relevant issues (e.g. `Closes #123`).
- Include tests where appropriate.
- Include documentation changes where necessary.
- Avoid unrelated changes in the same PR.
- Keep PRs focused and reasonably small; large changes are easier to review
  when split up.

## Code of Conduct

This project follows the [Code of Conduct](CODE_OF_CONDUCT.md). By
participating, you are expected to uphold it.

## Reporting Security Issues

Do not report security vulnerabilities through public Issues. See
[SECURITY.md](SECURITY.md).
