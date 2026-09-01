# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

While the major version is 0, the public surface — the database schema, the
environment variables and the image's behaviour — may change in a minor
release. Pin `ARCHIVUM_VERSION` and read this file before upgrading.

## [Unreleased]

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

[Unreleased]: https://github.com/jneto14/archivum/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/jneto14/archivum/releases/tag/v0.1.0
