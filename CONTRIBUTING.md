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

Local development uses [Laravel Sail](https://laravel.com/docs/sail) (Docker),
so the only requirement is Docker itself.

```text
Docker
Docker Compose
```

Setup:

```bash
composer install          # pulls in the sail script
cp .env.example .env
./vendor/bin/sail up -d
./vendor/bin/sail artisan key:generate
./vendor/bin/sail artisan migrate
./vendor/bin/sail artisan db:seed   # creates the first admin user, see output for its password
./vendor/bin/sail npm install
./vendor/bin/sail npm run build
```

The app is then available at http://localhost, and Mailpit (local mail
catcher) at http://localhost:8025.

If you already have PHP, Composer, Node and a local MySQL server set up and
prefer not to use Docker, the same commands work without the `sail`
wrapper — just run `composer install`, `npm install`, `php artisan migrate`,
etc. directly.

Run the full CI check suite (Pint, Larastan, Pest, ESLint, Prettier,
TypeScript) before opening a PR:

```bash
./vendor/bin/sail composer ci:check
```

Or individually:

```bash
./vendor/bin/sail composer lint       # Pint
./vendor/bin/sail composer test       # Pest (includes Pint + Larastan)
```

## Development Philosophy

Please read the "Development Philosophy" and "Implementation Priorities"
sections of the [README](README.md) before making architectural changes.
Archivum favours simple, explicit, testable Laravel code over premature
abstraction (no repositories, generic DTOs, or services without a real
architectural reason).

## Branch Naming

`main` is protected: all changes land through a Pull Request (force-push and
direct deletion are disabled). Branch names should follow:

```text
<type>/<short-description>

feature/document-search-filters
fix/workspace-isolation-leak
docs/api-authentication
chore/update-larastan
```

Common types: `feature`, `fix`, `docs`, `chore`, `refactor`, `test`.

## Commit Messages

Commits should follow [Conventional Commits](https://www.conventionalcommits.org/):

```text
<type>(<optional scope>): <short summary>

feat(documents): add MoveDocument action
fix(workspace): prevent cross-workspace document access
docs(readme): clarify self-hosted single-workspace mode
```

Common types: `feat`, `fix`, `docs`, `refactor`, `test`, `chore`, `perf`.

## Pull Requests

- Explain **what** changed and **why**.
- Reference relevant issues (e.g. `Closes #123`).
- Include tests where appropriate.
- Include documentation changes where necessary.
- Avoid unrelated changes in the same PR.
- Keep PRs focused and reasonably small; large changes are easier to review
  when split up.
- PRs merge via **squash merge** — the PR title/description becomes the commit
  message on `main`, so keep it accurate and following the Conventional
  Commits format above.

## Code of Conduct

This project follows the [Code of Conduct](CODE_OF_CONDUCT.md). By
participating, you are expected to uphold it.

## Reporting Security Issues

Do not report security vulnerabilities through public Issues. See
[SECURITY.md](SECURITY.md).
