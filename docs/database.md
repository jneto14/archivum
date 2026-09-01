# Database

MySQL 8.4. The schema is built by migrations in `database/migrations/`; there is
no committed schema dump.

## Conventions

**Primary keys are UUIDv7, not auto-increment.** Every domain model uses a
`char(36)` id generated with `Str::uuid7()`. UUIDv7 is time-ordered, so rows
still insert at the end of the index the way an auto-increment would, but an id
in a URL leaks neither a record count nor a creation order that can be walked.

**Foreign keys cascade on delete** where the child cannot outlive the parent —
deleting a document takes its attachments, tags and location history with it.

**Timestamps everywhere.** `created_at` is what the location history and the
activity log order by.

**JSON for genuinely open shapes only** — document metadata, task payloads,
organization level display settings. Anything queried by name gets a column.

## Tables

```text
users
password_reset_tokens
sessions
personal_access_tokens
passkeys

workspaces
workspace_user
workspace_limits

document_types
documents
document_attachments
document_locations
tags
document_tags

organization_schemes
organization_levels
organization_nodes
organization_rules

tasks
activity_log

cache, cache_locks
jobs, job_batches, failed_jobs
migrations
```

## How they relate

```text
Workspace
│
├── Users            through workspace_user, carrying the role
├── Limits           workspace_limits, one row, every column nullable
├── Document Types
├── Tags
│
├── Organization Scheme        at most one per workspace
│   ├── Levels                 ordered by position
│   ├── Nodes                  a tree, parent_id within a level's parent level
│   └── Rules                  matcher -> target level + preferred value
│
├── Documents
│   ├── Attachments
│   ├── Locations              history; the newest is the current one
│   └── Tags                   through document_tags
│
└── Tasks                      exports, bulk moves, text extractions
```

## The pieces worth knowing

### `workspace_user`

The membership record, carrying `role` (`admin` or `user`). A user has no global
`workspace_id`: they may belong to several workspaces with a different role in
each. Unique on `(workspace_id, user_id)`.

Platform-wide administration is separate: `users.is_platform_admin` is a flag
checked in a `Gate::before`, and it is what allows editing a workspace's limits.

### `document_locations`

A document's physical placement is a **history**, not a column. Each move
appends a row; the current location is the most recent one. That is what makes
"where was this in 2026?" answerable.

### `organization_nodes`

A generic tree. `level_id` says which level of the scheme a node belongs to, and
`parent_id` points at a node of the level above. Nothing in the schema knows
what a "cover" or a "drawer" is — see [organization.md](organization.md).

### `documents.ocr_text`

Text extracted from a document's attachments, concatenated onto the document
itself and indexed `FULLTEXT`. It is a mirror of what the attachments hold,
because Scout's `database` engine searches columns on the searchable model's own
table and cannot traverse a relation. See [search.md](search.md).

### `document_attachments`

Carries `disk`, `path`, `filename`, `mime_type`, `size`, `checksum`, plus the
OCR status, text, error and extraction timestamp.

The index on this table is `(document_id, size)`, not `document_id` alone. The
workspace storage total sums `size` over a workspace's attachments on the
dashboard and the Usage page, and with `size` outside any index every matching
row had to be read off disk to add one number up. Measured on MySQL 8.4 with one
workspace at 5,000 attachments: **11.6ms against 1.6ms**, and at 30,000
attachments 71.5ms against 7.7ms. `document_id` is the leftmost column, so the
foreign key stays satisfied and the single-column index is redundant.

`WorkspaceUsageTest` asserts through `EXPLAIN` that the sum stays index-only.

## Migrations in production

The `app` container runs `migrate --force` from its entrypoint on start, so a
deploy applies them without a separate step. `ARCHIVUM_MIGRATE=false` turns that
off if you would rather run them yourself.
