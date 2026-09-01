# Workspaces

A workspace is an isolated document environment: *Company Archive*, *Personal
Archive*, *Accounting*. It owns its documents, attachments, document types,
tags, organization scheme, settings, usage and limits.

```text
Workspace
├── Users
├── Documents
├── Attachments
├── Organization
├── Document Types
├── Tags
├── Settings
├── Usage
└── Limits
```

## Membership and roles

A user may belong to several workspaces, with a different role in each:

```text
User
├── Company A        → admin
├── Company B        → user
└── Personal Archive → admin
```

so there is no global `workspace_id` on a user. The relationship lives on
`workspace_user`, which carries the role.

| Role | Can |
| --- | --- |
| `admin` | Everything a user can, plus: workspace settings, invite and remove members, change roles, manage document types and tags, manage the organization scheme, view usage, run exports and bulk moves |
| `user` | View, create and edit documents, upload attachments, search, view physical locations |

Authorization is enforced through policies, never by the front end. A policy
checks membership of the workspace the record belongs to before it checks
anything else.

### Platform admin

Separate from workspace roles. `users.is_platform_admin` is a flag, granted by
the seeder to the bootstrap administrator and by
`php artisan platform-admin:grant`. It is checked in a `Gate::before`, and it is
what allows creating workspaces and editing their limits — a workspace admin
cannot raise their own ceiling.

## Adding people

There is no public registration. An admin invites by email:

- **The email already has an account** — they are added to the workspace with
  the chosen role.
- **It does not** — an account is created and an invitation email is sent so
  they can set their own password.

On a single-workspace installation a `User::created` listener joins every new
user to the sole workspace, so that an account created any other way (the
seeder, a console command) is not locked out of every route. The invitation flow
releases that auto-joined membership immediately, because the invitation assigns
the role the admin actually chose.

## Isolation

Every workspace-owned resource must be scoped. A request operating inside
workspace A must never reach a record in workspace B.

Two rules make that hold:

1. **Workspace context is resolved server-side**, in middleware, before any
   application operation runs. Business logic never trusts a workspace
   identifier supplied by the client.
2. **Route-model binding is not enough.** A document and a workspace bind
   independently, so a controller that receives both must check they belong
   together — otherwise the id in the URL is the authorization. The same applies
   to a rule and its scheme, an attachment and its document.

`WorkspaceIsolationTest`, `DocumentIsolationTest`, `AttachmentIsolationTest` and
`OrganizationIsolationTest` exist to keep this honest.

## Single-workspace mode

Archivum must be useful as a personal archive, not only as a multi-tenant one.

```dotenv
MULTI_WORKSPACE_ENABLED=false
```

With it off, the workspace switcher disappears, the workspace-creation routes
404, and the installation uses its one workspace. The model and the schema are
unchanged — the difference is entirely in what the interface offers.

## Limits

Limits belong to the workspace, not to individual users. All members share them:

```text
User A → 20 GB
User B → 15 GB
User C →  5 GB
        ───────
Total    40 GB / 50 GB
```

Four are configurable, and any of them may be `NULL`, meaning unlimited:

```text
storage_bytes
users
documents
attachments
```

These are configuration values. There is no pricing logic anywhere near them.

### Enforcement

**Server-side, before the write.** The front end may hide a button, but it is
never the authority.

```text
current usage + new file size > storage limit  →  reject
```

`CreateDocument`, `UploadAttachment` and `AddWorkspaceUser` each check their
limit before doing anything. Uploading several files at once is all-or-nothing:
a batch that would cross a limit is rejected whole, rather than storing files up
to the ceiling and failing on the rest.

## Usage

`CalculateWorkspaceUsage` computes the four totals and memoises them for the
life of the request, because they are read more than once — the sidebar badge on
every page, then the dashboard or the Usage page again for its own content. It
is bound `scoped` so every caller in a request shares one instance.

**Any action that creates or deletes a document, an attachment or a member must
call `forget()`.** The totals gate the limits and the limits are checked *before*
the write, so a stale memo would let a second write in the same request cross a
ceiling.

The totals are not cached across requests. They are indexed lookups — see
[database.md](database.md) — and a cache would buy single-digit milliseconds
while adding a stale-value path to numbers that enforce a limit.
