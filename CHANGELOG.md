# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the major version is 0, the public surface — the database schema, the
environment variables and the image's behaviour — may change in a minor
release. Pin `ARCHIVUM_VERSION` and read this file before upgrading.

## [Unreleased]

## [0.2.0] - 2026-09-02

Mostly about where an installation can live. It now works behind a reverse
proxy and can be served from a path prefix chosen at runtime, with `APP_URL` as
the only statement of where it sits. It also gains an opt-in demo mode, and a
fix for attachments being served as types the browser would run.

### Added

#### Self-hosting

- Reverse-proxy support: URLs are generated from `APP_URL`, and
  `TRUSTED_PROXIES` is finally applied — it was documented but never wired up,
  which cost a session cookie not marked `Secure` and a login throttle counting
  the whole internet as one client.
- The whole application can be served from a path prefix chosen at runtime.
  Route URLs, chunk loading and fonts all follow `APP_URL`, so one published
  image works under any prefix with no rebuild.
- An installation is kept out of search engines: `robots.txt` disallows
  everything, the layout carries a `noindex` meta tag, and every response —
  attachments, previews and exported CSVs included — carries `X-Robots-Tag`.
  This is not access control; it keeps an installation out of Google, not out
  of reach.
- Documentation for putting the application behind a reverse proxy, serving it
  under a path, and a worked recipe for a public demo that needs all three at
  once.
- The Docker Hub overview lives in the repository as a template and is
  published from the tag on each release, so it can no longer describe an
  install recipe that has since changed.

#### Demo mode

- An opt-in demo mode with a nightly reset that drops every record, deletes
  every uploaded file and reseeds a small archive with real extracted text.
  `demo:reset` refuses unless two independent locks clear: `DEMO_MODE`, and a
  `DEMO_RESET_CONFIRM` that has to repeat the installation's own `APP_URL` — so
  a demo `.env` copied onto a real installation stops matching the moment the
  host changes.
- Demo restrictions on the actions that would leave the next visitor locked out
  or with nothing to look at: password and email changes, account and workspace
  deletion, creating workspaces and editing limits. Mail is redirected at the
  transport rather than per sender. The interface stops offering what the
  server refuses, so a demo does not read as a broken application.

#### Development

- A test runner for `resources/js` — Vitest, inside `composer ci:check` — with
  first tests over the behaviour nothing else could see: locale and timezone
  rendering, the components that are supposed to be invisible on almost every
  installation, and the translation fallback chain.

### Changed

- `ASSET_URL` is no longer needed. `APP_URL` is now the only statement of where
  an installation lives.
- Every outstanding dependency updated, majors included, and ESLint moved to
  10, TypeScript to 7. `eslint-plugin-react` is replaced by
  `@eslint-react/eslint-plugin`, which removes the last `overrides` entry from
  `package.json`.
- Linting fails on warnings (`--max-warnings=0`), which is how sixteen live
  React rule violations had accumulated unseen.
- Dependabot groups split so a held-back major stops blocking routine bumps.
- The demo banner and login credentials are drawn on the application's own
  `muted` surface rather than in raw amber. Being on a demo is context, not a
  warning.

### Fixed

- Behind a TLS-terminating proxy, redirects came back as `http://` and the
  browser either refused them or quietly left TLS behind.
- The MySQL healthcheck read its password through compose interpolation rather
  than the container's own environment, so with an `--env-file` the two could
  disagree, the probe blocked on a password prompt, and nothing in the stack
  ever started.
- The document form's date field was a native `<input type="date">`, which
  renders in the browser's locale — a Portuguese installation showed
  `mm/dd/yyyy`.
- The preview dialog decided what it could render from the mime type alone, so
  an SVG attachment showed a broken image instead of the "cannot preview"
  message. Both sides now read the same list.
- Metadata rows, organization level rows and the attachment upload queue were
  keyed by array index, so removing one moved the values out from under the
  caret and the focus of the row that slid up into its place.
- A `setTimeout` in the two-factor modal was never cleared.

### Security

- An uploaded attachment is never served as a type the browser will run. The
  content type is stated from a list rather than detected from the file;
  anything outside it is served as `application/octet-stream` with an
  attachment disposition, and both routes send `X-Content-Type-Options:
  nosniff`. `image/svg+xml` is deliberately absent from the list. Uploads stay
  unrestricted. Previously an uploaded `invoice.html` came back as `text/html`
  and its script ran on the application's own origin with the viewer's session.

## [0.1.0] - 2026-09-01

The first tagged release. Everything below shipped in it.

### Added

#### Foundation

- Laravel 13 + React/Inertia application scaffold, with Sail for local
  development and a CI workflow running lint, static analysis, types and tests.
- UUIDv7 primary keys for every domain model, rather than auto-increment
  integers.
- Repository community files: CONTRIBUTING, CODE_OF_CONDUCT, SECURITY, LICENSE,
  issue and pull request templates, CODEOWNERS and Dependabot.

#### Workspaces

- Workspaces and multi-workspace user membership, with role-based authorization
  (`admin`/`user`), isolation enforced through policies, server-side workspace
  context resolution, and a single-workspace mode
  (`MULTI_WORKSPACE_ENABLED`).
- Inviting users to a workspace by email, including accounts that do not exist
  yet, with an invitation email that lets them set their own password.
- Workspace usage and configurable limits — storage, users, documents,
  attachments — enforced server-side before each write.
- A "Usage & limits" page, a workspace Settings page with API tokens and
  deletion, a members page, and an Activity page.
- Platform administrators, separate from workspace roles, able to create
  workspaces and edit their limits.

#### Documents

- The Document domain: types, configurable JSON metadata, tags, and physical
  location history.
- Attachments — uploads, scans and photographs — with multi-file upload
  validated as a batch against the workspace's limits.
- A paginated in-browser attachment preview.
- Document types and Tags management pages.

#### Physical organization

- Configurable organization schemes, levels, nodes and rules, so a filing
  system is configuration rather than schema.
- Automatic location suggestion, honouring rules and level capacity.
- One scheme per workspace, node deletion, and bulk migration of every document
  under a node to another node.
- Appending and removing scheme levels, with alphabetical-strategy levels
  capped at 26.

#### Search and text extraction

- Document search through Laravel Scout.
- Background text extraction from attachments: a PDF's embedded text layer
  where it has one, OCR through Tesseract where it does not, fed into the
  search index.
- Two search modes — whole words, and word-starts-with — both served from the
  full-text index.

#### Background work

- A generic task-tracking system with a Tasks page: exports, bulk moves and
  text extractions, each with its status and a retry.
- Workspace document export to CSV, delivered by email, with a signed download
  link and scheduled pruning of expired exports.

#### Interface

- Application shell, navigation and workspace switcher.
- Per-user locale and timezone preferences, with backend and frontend
  localisation in English and Portuguese.
- Strong password enforcement with a strength meter, passkeys, and two-factor
  authentication.
- A design consistency and layout cleanup pass across every screen.
- Archivum's own visual identity: the mark, favicons, branded emails, and a
  link-preview card.

#### Self-hosting

- A production Docker image built on FrankenPHP, published to Docker Hub and
  GHCR for `amd64` and `arm64` on every `v*` tag.
- A production compose stack: application, queue worker, scheduler, MySQL and
  Redis, from one image differing only by command.
- Documentation split into `docs/`, covering architecture, database, workspaces,
  documents, organization, search, storage, text extraction, the API surface,
  development and deployment.

### Changed

- Public self-registration is disabled; accounts exist through the seeder or an
  invitation.
- Cache and sessions moved to Redis; the queue stays on the database
  deliberately, because a Redis without persistence loses queued work.
- Logs rotate daily rather than growing as a single file.

### Fixed

- Dates render in the application locale and the user's timezone.
- Hover-prefetch removed from Inertia links, which was issuing requests for
  pages nobody visited.
- The workspace storage total is answered from an index instead of reading every
  attachment row.
- A brand-new user invited on a single-workspace installation is added with the
  role the admin chose, rather than failing with "already a member".

[Unreleased]: https://github.com/jneto14/archivum/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/jneto14/archivum/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/jneto14/archivum/releases/tag/v0.1.0
