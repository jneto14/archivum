# Archivum

Open-source document management system focused on organizing physical documents and their digital representations.

Archivum provides a searchable database for documents stored physically in configurable covers, folders, binders, cabinets, drawers, or other storage structures.

The core principle is:

> Logical organization and physical organization are separate concepts.

A document can be categorized, tagged and searched independently from where its physical copy is stored.

---

## Goals

Archivum should allow users to:

- Register physical documents.
- Upload scans, PDFs and photographs.
- Search documents and metadata.
- Know exactly where a physical document is stored.
- Configure different physical organization systems.
- Automatically suggest physical locations.
- Keep a history of document movements.
- Support OCR and full-text search.
- Organize documents using configurable rules.
- Support multiple independent workspaces.
- Support multiple users per workspace.
- Enforce workspace-level resource limits.
- Remain useful as a self-hosted open-source application.

---

## Scope

The initial project focuses on:

- Workspaces
- Users
- Workspace memberships
- Roles
- Documents
- Document types
- Configurable metadata
- Digital attachments
- Physical locations
- Configurable organization schemes
- Organization levels and nodes
- Organization rules
- Document location history
- Search through Laravel Scout
- Usage and limits
- Authentication and authorization
- React/Inertia interface

SaaS billing, subscriptions and external provisioning are intentionally outside the project scope.

---

## Architecture

```text
                  Archivum
                     |
        +------------+------------+
        |                         |
    Workspace A              Workspace B
        |                         |
   +----+----+               +----+----+
   |         |               |         |
 Users   Documents         Users   Documents
             |
        +----+----+
        |         |
    Attachments  Search
```

Archivum must work independently as a self-hosted application.

---

## Workspace

A `Workspace` represents an isolated document environment.

Examples:

```text
Company Archive
Personal Archive
Accounting Archive
Family Documents
```

A Workspace owns:

- Users
- Documents
- Attachments
- Document types
- Metadata configuration
- Organization schemes
- Physical locations
- Tags
- Settings
- Usage
- Limits

Conceptually:

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

---

## Multi-Workspace Users

A user may belong to multiple Workspaces.

Example:

```text
User
├── Company A → admin
├── Company B → user
└── Personal Archive → admin
```

A user therefore does not have a single global `workspace_id`.

The relationship is represented through a membership model:

```text
users
    |
    +--- workspace_user
              |
              +--- workspace
```

Suggested fields:

```text
workspace_user
----------------
workspace_id
user_id
role
created_at
updated_at
```

Initial roles:

```text
admin
user
```

### Admin

A Workspace admin can:

- Manage workspace settings.
- Manage users.
- Invite users.
- Remove users.
- Change membership roles.
- Manage document types.
- Manage metadata configuration.
- Manage physical organization.
- View usage and limits.
- Manage documents.

### User

A normal user can:

- View documents.
- Create documents.
- Edit documents where permitted.
- Upload attachments.
- Search documents.
- View physical locations.

Exact authorization should be implemented through Laravel Policies.

---

## Workspace Isolation

All Workspace-owned resources must be isolated.

Examples:

```text
Documents
Attachments
Tags
Document Types
Organization Nodes
Organization Schemes
```

A request operating inside Workspace A must never be able to access resources belonging to Workspace B.

Workspace context should be resolved before application operations are executed.

Business logic must never rely solely on frontend-provided workspace identifiers.

---

## Self-Hosted Mode

Archivum must work without any external service.

A self-hosted installation may operate as:

```text
Single Workspace
```

or:

```text
Multiple Workspaces
```

Configuration should allow installations to disable multi-workspace functionality where appropriate.

Example:

```env
MULTI_WORKSPACE_ENABLED=false
```

When disabled, the installation can automatically use a default Workspace.

The Workspace model and database structure should still exist.

---

## Workspace Limits

Resource limits belong to the Workspace, not individual users.

Example:

```text
Workspace: Company A

Storage:
50 GB

Users:
Unlimited

Documents:
10,000
```

All users share Workspace limits.

Example:

```text
User A → 20 GB
User B → 15 GB
User C → 5 GB

Total → 40 GB / 50 GB
```

The storage limit belongs to the Workspace rather than individual users.

---

## Limits

The system should support configurable resource limits.

Initial limits may include:

```text
storage
users
documents
attachments
```

A limit can be unlimited.

```text
NULL = unlimited
```

Limits are configuration values and must not contain commercial pricing logic.

---

## Usage

The application should expose current Workspace usage.

Example:

```text
Storage:
24.3 GB / 50 GB

Users:
8 / Unlimited

Documents:
4,281 / 10,000

Attachments:
8,912
```

Usage should be available to:

- The web interface
- Internal application services

---

## Usage & Limits Page

A dedicated Workspace information page should expose:

### Workspace Information

```text
Workspace
Company A

Status
Active

Created
22 August 2026
```

### Storage

```text
Storage

24.3 GB / 50 GB

██████████░░░░░░░░░░ 48.6%

25.7 GB available
```

### Documents

```text
Documents

4,281 / 10,000
```

### Users

```text
Users

8 / Unlimited
```

The UI should clearly indicate whether a resource is:

- Available
- Near its limit
- At its limit
- Unlimited

---

## Limits Enforcement

Limits must be enforced server-side.

The frontend must never be the authority for resource limits.

For example, when uploading a file:

```text
Current usage
+
New file size
>
Storage limit
```

The backend must reject the upload.

The same principle applies to:

```text
CreateUser
CreateDocument
UploadAttachment
```

when corresponding limits exist.

---

# Documents

## Document

A `Document` represents the logical document.

Example:

```text
Invoice #FT2026/1234

Type: Invoice
Date: 2026-08-20
Supplier: Example Lda
Vehicle: 12-AA-34

Tags:
    - vehicle
    - 2026
    - maintenance
```

A document can have multiple digital attachments:

```text
Document
├── scan.pdf
├── front.jpg
├── back.jpg
└── additional.pdf
```

The document itself does not assume a specific physical storage structure.

---

## Document Types

Document types describe the logical nature of a document.

Examples:

```text
Invoice
Contract
Insurance
Tax Document
Receipt
Vehicle Document
Warranty
```

Types are independent from physical locations.

---

## Dynamic Metadata

Documents support metadata depending on their document type.

Example:

```json
{
    "supplier": "Example Lda",
    "vehicle_registration": "12-AA-34",
    "amount": 1250.50
}
```

The initial implementation uses MySQL JSON for dynamic metadata.

Frequently queried metadata fields can later receive dedicated indexed/generated columns when justified by actual usage.

---

# Physical Organization

Physical storage is represented through a configurable hierarchy.

The application must not hard-code concepts such as:

- Cover
- Folder
- Letter
- Year
- Cabinet
- Drawer
- Shelf
- Position

Instead, these are configurable organization levels.

Example:

```text
Cover
└── Letter
    └── Position
```

Another installation could use:

```text
Cabinet
└── Drawer
    └── Folder
        └── Position
```

Or:

```text
Year
└── Document Type
    └── Position
```

---

## Organization Schemes

An `OrganizationScheme` defines how a Workspace physically organizes documents.

Example:

```text
Traditional Archive

Level 1: Cover
Level 2: Letter
Level 3: Position
```

Another:

```text
Annual Archive

Level 1: Year
Level 2: Cover
Level 3: Position
```

Each Workspace may have one or more organization schemes.

---

## Organization Levels

An `OrganizationLevel` describes one level in a hierarchy.

Example:

```text
Cover
Letter
Position
```

Levels can define:

- Name
- Key
- Position in hierarchy
- Capacity
- Value generation strategy
- Display settings
- Metadata/settings

---

## Organization Nodes

An `OrganizationNode` represents an actual physical location.

Example:

```text
Cover 001
├── A
│   ├── 1
│   ├── 2
│   ├── 3
│   ├── 4
│   └── 5
├── B
│   ├── 1
│   └── 2
└── C
```

The database represents this as a generic tree:

```text
OrganizationNode
    parent
       ↓
OrganizationNode
       ↓
OrganizationNode
```

A document may therefore have a physical location:

```text
001-A-3
```

The exact representation is determined by the configured organization scheme.

---

## Organization Rules

Rules determine where documents should preferably be stored.

Example:

```text
Document Type: Invoice
Preferred Section: A
```

When adding an invoice:

```text
Invoice
    ↓
Preferred section: A
    ↓
Find first available location
    ↓
001-A-4
```

Rules are recommendations rather than immutable constraints.

---

## Document Location History

Physical assignments are tracked separately from documents.

Example:

```text
Document #123

2026-08-22
001-A-3

2027-03-14
014-C-2
```

The current location is the active location assignment.

---

# Digital Files

Files are stored outside the relational database.

The database stores metadata such as:

```text
disk
path
filename
mime_type
size
checksum
```

Storage uses Laravel's filesystem abstraction.

Supported storage may include:

```text
Local filesystem
S3
MinIO
Other Laravel filesystem disks
```

---

## Search

Search is implemented through Laravel Scout.

The application should use Scout as the search abstraction.

Possible engines include:

```text
Database
Meilisearch
```

The application must not depend directly on a specific search engine.

The initial implementation should prefer a database-compatible Scout engine where practical.

---

## Search Filters

Scout handles textual search.

Structured filtering remains close to the relational database where appropriate.

Example:

```text
Search:
BMW 320d

Filters:
Type = Invoice
Year = 2026
```

Search state should preferably be represented in the URL.

---

# Attachment Text Extraction

Uploaded attachments have their text extracted in the background, so a document
can be found by what is written on the page and not only by its title.

OCR is the fallback, not the first move. A PDF that was born digital — an
exported invoice, a letter printed to PDF — already carries a text layer, and
reading it directly is instant and exact where OCR would be slower and would
introduce recognition errors. Only files with no text of their own, which is
most of what an archive of physical documents holds, are rasterized and sent to
OCR.

```text
Upload PDF / image
        ↓
Store attachment
        ↓
Queue ExtractAttachmentText
        ↓
   PDF? ──yes──▶ read the embedded text layer (pdftotext)
    │                     │
    │              enough text? ──yes──▶ done
    │                     │
    │                     no
    │                     ↓
    └──────────▶ rasterize pages (Imagick + Ghostscript) ──▶ OCR (tesseract)
                          ↓
              store the text on the attachment
                          ↓
        mirror it onto the document and re-index for search
```

## System requirements

These are binaries, not PHP extensions; `composer install` does not bring them.
They are installed in three places — the development image
(`docker/8.5/Dockerfile`), the production image
(`docker/production/Dockerfile`) and CI — and `OcrToolchainParityTest` fails if
those three ever stop agreeing.

They must be present wherever the **queue worker** runs, not just the web
container: extraction happens on the queue, so a worker without them reports
every attachment as unreadable while the site looks perfectly healthy.

| Needed for | Package |
| --- | --- |
| OCR | `tesseract-ocr`, plus `tesseract-ocr-<lang>` for each configured language |
| Reading a PDF's text layer | `poppler-utils` (`pdftotext`) |
| Rasterizing scanned PDFs | `ghostscript`, behind PHP's `imagick` extension |

A language pack per configured language matters: tesseract runs happily without
one and simply recognises nothing, which is indistinguishable from a blank page.

## Configuration

The `archivum.ocr` block in `config/archivum.php` covers the languages, the
threshold that separates "has a text layer" from "is a scan", and caps on pages
and runtime. Set `OCR_ENABLED=false` to switch the whole thing off.

An installation without the binaries still works. Extraction records itself as
unavailable on the attachment and the document page says so, rather than
failing uploads or silently doing nothing.

## Where the state shows up

Twice, for two different readers. The attachment carries the text and its
status, shown on the document page next to the file it belongs to. Each
extraction is also a `Task`, so the workspace's Tasks page lists them alongside
exports and bulk moves — which is where an admin can see a failure and retry it
without opening documents one at a time.

Unlike the other task types, extraction takes no per-workspace lock: it is
scoped to a single file, so several run concurrently. The Tasks page is
paginated for the same reason — one row per uploaded file adds up.

## Searching it

Two modes, because one query string has to serve two very different haystacks.
A title is short, so a substring match over it is cheap and forgiving. The
extracted text is indexed with MySQL FULLTEXT, which matches whole words only —
so by default "fatur" finds a document *titled* "Fatura" but not one whose scan
says it.

| Mode | Attachment text | Title |
| --- | --- | --- |
| Whole words (default) | Whole-word match, natural language mode | Substring |
| Word starts with | Prefix match, boolean mode with a trailing wildcard | Substring |

Both stay on the full-text index, so neither scans the stored pages. The cost
of that is that no mode matches the *middle* of a word: "atura" will not find
"fatura". In the broader mode every typed term must appear somewhere — title or
text — since ORing them returns most of the archive as soon as someone types
three words.

Punctuation is treated as a separator, not as syntax: boolean mode reads
`+ - * " ( ) ~` as operators, so "edp-2026" is split into two terms rather than
being read as "edp but not 2026".

Extracted text is stored per attachment, and mirrored onto the document as a
single concatenated column. The mirror exists because Scout's `database` engine
searches columns on the searchable model's own table and cannot traverse a
relation — without it, text held on the attachments would never be matched by a
document search.

---

# QR Codes

Physical storage containers can optionally have QR codes.

Scanning a QR code can open the corresponding physical location.

---

# Laravel 13 Architecture

Archivum uses:

- Laravel 13
- Inertia.js
- React
- TypeScript
- Tailwind CSS
- shadcn/ui
- Laravel Scout

Architecture:

```text
Browser
   │
   ▼
React + Inertia
   │
   ▼
Laravel 13
   │
   ├── Controllers
   ├── Form Requests
   ├── Actions
   ├── Policies
   ├── Eloquent
   ├── Scout
   └── Jobs
       │
       ├── MySQL
       └── File Storage
```

---

# Laravel Packages

The project should use packages where they provide meaningful functionality without unnecessary abstraction.

## Production

### Laravel Scout

Document and metadata search.

```text
laravel/scout
```

### Laravel Sanctum

Authentication for future/internal API endpoints.

```text
laravel/sanctum
```

### Spatie Laravel Permission

Roles and permissions where global or fine-grained permissions are required.

```text
spatie/laravel-permission
```

Workspace membership roles remain associated with the `workspace_user` membership model.

Policies remain responsible for Workspace-specific authorization.

### Spatie Laravel Media Library

Management of scans, photographs, PDFs and other document attachments.

```text
spatie/laravel-medialibrary
```

### Spatie Laravel Activitylog

Audit history for important operations.

```text
spatie/laravel-activitylog
```

This is particularly useful for physical document movements and administrative changes.

## Development

### Pest

Automated testing.

```text
pestphp/pest
```

### Larastan

Static analysis.

```text
larastan/larastan
```

### Laravel Pint

Code formatting.

```text
laravel/pint
```

### Laravel Boost

AI-assisted Laravel development.

```text
laravel/boost
```

## Optional

### Intervention Image

Image processing when required beyond the capabilities used by the media library.

```text
intervention/image
```

### Meilisearch

Optional external Scout search engine for larger installations.

The project should not require Meilisearch initially.

---

# Packages Intentionally Not Required

Avoid unnecessary packages.

The initial architecture should not require:

- A tenancy package
- Laravel Cashier
- Laravel Passport
- A generic repository-pattern package
- A generic DTO package

Workspace isolation is implemented directly through the application's domain model.

OAuth2 is not required for the initial API; Sanctum is sufficient.

---

# Frontend

The Laravel 13 React starter kit should be used as the starting point.

Expected stack:

```text
React
TypeScript
Inertia
Tailwind CSS
shadcn/ui
```

Suggested structure:

```text
resources/js/
├── Components/
│   ├── Documents/
│   ├── Organization/
│   ├── Workspace/
│   ├── Attachments/
│   └── UI/
│
├── Layouts/
│
├── Pages/
│   ├── Dashboard.tsx
│   ├── Documents/
│   │   ├── Index.tsx
│   │   ├── Show.tsx
│   │   ├── Create.tsx
│   │   └── Edit.tsx
│   ├── Organization/
│   ├── Workspace/
│   │   ├── Show.tsx
│   │   ├── Users.tsx
│   │   └── Usage.tsx
│   └── Settings/
│
├── hooks/
├── lib/
└── types/
```

---

# Workspace UI

The application should clearly expose the active Workspace.

For users belonging to multiple Workspaces, a Workspace switcher should be available.

Example:

```text
┌─────────────────────────────┐
│ Company A              ▼    │
├─────────────────────────────┤
│ Company A                   │
│ Company B                   │
│ Personal Archive            │
└─────────────────────────────┘
```

Changing Workspace changes the application context.

All subsequent operations are scoped to the selected Workspace.

---

# Forms

Laravel Form Requests are responsible for validation and authorization.

Examples:

```text
StoreDocumentRequest
UpdateDocumentRequest
MoveDocumentRequest

StoreWorkspaceRequest
UpdateWorkspaceRequest
StoreWorkspaceUserRequest

StoreSchemeRequest
StoreLevelRequest
StoreNodeRequest
```

Business logic should not live inside Form Requests.

Application operations belong in Actions.

---

# Laravel Application Structure

Suggested structure:

```text
app/
├── Actions/
│   ├── Documents/
│   ├── Workspace/
│   ├── Organization/
│   └── Attachments/
│
├── Http/
│   ├── Controllers/
│   │   ├── Documents/
│   │   ├── Workspace/
│   │   ├── Organization/
│   │   ├── Settings/
│   │   └── Api/
│   ├── Requests/
│   └── Resources/
│
├── Jobs/
├── Events/
├── Listeners/
├── Models/
├── Policies/
└── Services/
```

---

# Actions

Actions represent meaningful application operations.

Examples:

```text
CreateWorkspace
UpdateWorkspace

AddWorkspaceUser
RemoveWorkspaceUser
ChangeWorkspaceUserRole

CreateDocument
UpdateDocument
MoveDocument
DeleteDocument

CreateScheme
CreateOrganizationNode
FindAvailableLocation
ApplyOrganizationRules

UploadAttachment
DeleteAttachment
```

---

# Services

Services should only be introduced when they provide a meaningful reusable abstraction.

Examples:

```text
OcrService
StorageService
```

Interfaces should only be introduced where multiple implementations or an external boundary justify them.

---

# Repositories

Repositories are intentionally not part of the default architecture.

Eloquent is the persistence layer.

Avoid repositories that only wrap:

```php
Document::query()
```

unless a real abstraction is required later.

---

# Organization Engine

Physical organization logic should live outside controllers.

Example:

```text
ApplyOrganizationRules
        ↓
FindAvailableLocation
        ↓
OrganizationNode
```

---

# Queues

Queues should be used for operations that do not need to block HTTP requests.

Examples:

```text
ProcessAttachmentOcr
GenerateThumbnail
ReindexDocument
ImportDocuments
BulkMoveDocuments
```

Nothing queued runs unless a worker runs. See Deployment below.

---

# Deployment

`compose.prod.yaml` pulls the published image and runs five services. It is not
`compose.yaml`, which is Sail's development stack.

```text
app        FrankenPHP, serving public/          :80
worker     queue:work                           same image
scheduler  schedule:work                        same image, one replica
mysql
redis
```

## Installing

```bash
curl -O https://raw.githubusercontent.com/jneto14/archivum/main/compose.prod.yaml

cat > .env <<EOF
APP_NAME=Archivum
APP_ENV=production
APP_DEBUG=false
APP_URL=https://archivum.example.com
# 32 random bytes in base64 — the same thing artisan key:generate produces.
APP_KEY=base64:$(openssl rand -base64 32)

# Not root: MySQL refuses to start with MYSQL_USER=root.
DB_HOST=mysql
DB_DATABASE=archivum
DB_USERNAME=archivum
DB_PASSWORD=change-me

REDIS_HOST=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=database

ADMIN_EMAIL=you@example.com
ADMIN_PASSWORD=change-me-too

# Pin a release rather than riding latest, so upgrading is a choice.
ARCHIVUM_VERSION=latest
EOF

docker compose --env-file .env -f compose.prod.yaml up -d
docker compose --env-file .env -f compose.prod.yaml exec app php artisan db:seed --force
```

Written out rather than copied from `.env.example`, which is the development
file: it points at `127.0.0.1` and connects as `root`, and MySQL's entrypoint
refuses to start at all with `MYSQL_USER=root`.

`--env-file` matters. Compose reads variables from two places and they are not
the same: `env_file:` inside the file passes them into the containers, while
`${...}` in the file itself is interpolated by compose from the project `.env`
or from `--env-file`. `APP_PORT`, the `DB_*` pair used to create the database,
and `ARCHIVUM_*` are all the second kind, so without the flag they quietly fall
back to their defaults — a stack that ignores `APP_PORT` and tries to bind 80.

Migrations run from the container's entrypoint, so there is no separate step for
them. The seed is what creates the first administrator and the default
workspace — without it there is no account to log in with. Leaving
`ADMIN_PASSWORD` unset makes the seeder generate one and print it **once**.

## Images

Published on every `v*` tag to two registries, holding the same `amd64` and
`arm64` images:

```text
jnweb/archivum:1.2.3            Docker Hub
ghcr.io/jneto14/archivum:1.2.3  GHCR
```

Docker Hub is the default because a short name is only ever resolved there —
`docker pull jnweb/archivum` expands to `docker.io/jnweb/archivum` and Docker
looks nowhere else. Switch registries with `ARCHIVUM_IMAGE`, which is worth
doing where Docker Hub's anonymous pull limit is a problem:

```dotenv
ARCHIVUM_IMAGE=ghcr.io/jneto14/archivum
ARCHIVUM_VERSION=1.2.3
```

To build the image yourself rather than pull it:

```bash
docker build -f docker/production/Dockerfile -t jnweb/archivum:local .
ARCHIVUM_VERSION=local docker compose -f compose.prod.yaml up -d
```

`ARCHIVUM_ENV_FILE` points the stack at a different file, which is what makes it
safe to run beside a development checkout — `.env` there is Sail's, with
`APP_ENV=local` and a `DB_HOST` that means something else entirely:

```bash
docker compose --env-file .env.prod -f compose.prod.yaml -p archivum-prod up -d
```

with `ARCHIVUM_ENV_FILE=.env.prod` set inside that file, so both mechanisms
read it. Give it its own `-p` project name and an `APP_PORT` other than 80 and
the two stacks run side by side.

## Changing configuration

Almost all of it is environment variables, and none of those are in the image.
Edit `.env`, then:

```bash
docker compose --env-file .env -f compose.prod.yaml up -d
```

**Not `restart`.** That restarts the same containers with the environment they
were created with, so an edited `.env` has no effect at all:

```text
.env edited, then compose restart  ->  old value, silently
.env edited, then compose up -d    ->  new value
```

This works because the config cache is built by the entrypoint when the
container starts, not when the image is built. That is what makes one image
environment-agnostic: the same `jnweb/archivum:1.2.3` runs anywhere, and
nothing about your installation is baked into it.

`OCR_JOB_TIMEOUT` shows why the distinction bites. It moves three things at
once — the job's timeout, the queue's `retry_after`, and the worker's
`--timeout`, which compose interpolates into the command when it creates the
container. After a `restart` the job would run longer while the worker still
killed it on the old clock.

What *is* in the image is code: `config/*.php`, `docker/production/php.ini`,
the `Caddyfile`. Changing those means a new image version. A bind mount over
one of them works as an escape hatch, but it is not the route to take twice.

## Upgrading

```bash
# Change ARCHIVUM_VERSION in .env, then:
docker compose --env-file .env -f compose.prod.yaml pull
docker compose --env-file .env -f compose.prod.yaml up -d
```

Replacing the containers is what applies the new code, and replacing the worker
**is** `queue:restart` — a running worker holds the old classes in memory and
will not pick up a new job class on its own. Migrations run on start.

## One image, three roles

The three application services differ only by their command. That is
deliberate: attachment text extraction runs on the queue, so a worker built
without tesseract, poppler and ghostscript would fail every extraction while
the web container passed its health check.

The scheduler runs **one replica**. `schedule:work` keeps its own clock, so a
second instance prunes exports and cleans the activity log a second time.

## What each role needs

| | |
| --- | --- |
| Attachments | The `archivum-attachments` volume, mounted at `/app/storage/app`. The rest of `storage/` is per-container cache and logs and must not be shared |
| Migrations | Run by the `app` container on start. Set `ARCHIVUM_MIGRATE=false` to run them yourself |
| Config cache | Built on start by every role, because it bakes in the environment and the environment only exists at run time |
| Deploys | Replacing the worker container **is** `queue:restart`. A running worker holds the old code in memory, so the container has to go, not just the files |

## Environment

`compose.prod.yaml` reads `.env`. At minimum set `APP_KEY`, `APP_URL` and
`DB_PASSWORD`; everything else has a working default in `.env.example`.

Two things differ from development:

```dotenv
CACHE_STORE=redis      # a database cache store makes a cache hit a query
SESSION_DRIVER=redis
QUEUE_CONNECTION=database
REDIS_HOST=redis       # the service name, like DB_HOST=mysql
```

The queue stays on the database on purpose. It carries the app's real work, and
a Redis without persistence loses it on restart, which strands an attachment as
"queued" for good. Cache and sessions are safe to lose; jobs are not.

Redis runs with no eviction policy for the same reason: sessions live on
database 0 and the cache on database 1, and an `allkeys` policy under memory
pressure would sign people out to make room for cache entries.

## Timeouts

Three numbers have to stay in order, or a long OCR run is handed to a second
worker and the work happens twice:

```text
queue retry_after  >  worker --timeout  >=  job timeout  >=  max_pages × ocr.timeout
      3000                 2700                2700               2400
```

All of them derive from `OCR_MAX_PAGES` and `OCR_TIMEOUT`, so raising the page
cap moves the whole chain. `QueueTimeoutTest` fails if it stops holding.

---

# Policies

Authorization should be implemented using Laravel Policies.

Examples:

```text
WorkspacePolicy
WorkspaceUserPolicy
DocumentPolicy
OrganizationSchemePolicy
AttachmentPolicy
```

Workspace membership must be checked before accessing Workspace resources.

---

# Authentication

The primary application uses Laravel session authentication through Inertia.

API authentication uses Laravel Sanctum where required.

The two authentication flows should remain independent.

---

# Database Model

Initial domain model:

```text
workspaces
users
workspace_user

document_types

organization_schemes
organization_levels
organization_nodes
organization_rules

documents
document_attachments
document_locations

tags
document_tags

workspace_limits
```

Conceptually:

```text
Workspace
│
├── Users
├── Limits
├── Document Types
│
├── Organization Schemes
│   ├── Levels
│   ├── Nodes
│   └── Rules
│
└── Documents
    ├── Attachments
    ├── Locations
    └── Tags
```

---

# Testing

Important business rules require automated tests.

At minimum:

```text
WorkspaceTest
WorkspaceMembershipTest
WorkspaceLimitTest
WorkspaceUsageTest

OrganizationSchemeTest
OrganizationLevelTest
OrganizationNodeTest
FindAvailableLocationTest
ApplyOrganizationRulesTest

CreateDocumentTest
MoveDocumentTest
DocumentSearchTest

WorkspaceIsolationTest
```

Tests should specifically verify that:

- Users cannot access other Workspaces.
- Workspace admins can manage their Workspace.
- Limits are enforced server-side.
- Unlimited limits work correctly.
- Suspended Workspaces behave correctly.
- Physical locations cannot exceed configured capacity.
- Documents remain correctly associated with their Workspace.

---

# Project Management

The project is open source.

GitHub is the public collaboration platform.

YouTrack may be used internally for development management, but it is not part of Archivum.

```text
GitHub
    =
Public collaboration

YouTrack
    =
Internal development management
```

Public contributors must not require YouTrack access.

---

# GitHub Issues

GitHub Issues should be enabled.

Suggested templates:

```text
.github/
└── ISSUE_TEMPLATE/
    ├── bug.yml
    ├── feature.yml
    └── documentation.yml
```

---

# GitHub Labels

Suggested labels:

```text
bug
enhancement
documentation
question

good first issue
help wanted

needs triage
in progress
blocked

priority: low
priority: medium
priority: high

area: backend
area: frontend
area: database
area: search
area: storage
area: ocr
area: organization
area: workspace
area: api
area: infrastructure
```

---

# GitHub Discussions

Discussions should be enabled for:

- Questions
- Architecture discussions
- Ideas
- General usage
- Community feedback
- Use cases

Discussions can become Issues when actionable work is identified.

---

# Pull Requests

Pull Requests should:

- Explain what changed.
- Explain why.
- Reference relevant GitHub Issues.
- Include tests where appropriate.
- Include documentation changes where necessary.
- Avoid unrelated changes.

---

# Documentation

Technical documentation should live inside `/docs`.

Suggested structure:

```text
docs/
├── architecture.md
├── database.md
├── workspace.md
├── organization.md
├── search.md
├── storage.md
├── api.md
├── ocr.md
├── development.md
└── deployment.md
```

GitHub Wiki can contain:

```text
Installation
Getting Started
Managing Documents
Physical Organization
Workspaces
Users
Search
OCR
Storage
API
Troubleshooting
FAQ
```

Important architectural documentation should remain version-controlled.

---

# Repository Structure

```text
archivum/
│
├── app/
├── bootstrap/
├── config/
├── database/
├── public/
├── resources/
│   └── js/
├── routes/
├── storage/
├── tests/
│
├── docker/
│   ├── 8.5/            # Sail's development runtime
│   ├── mysql/
│   └── production/     # the image that actually ships
│
├── docs/
│
├── .github/
│   ├── ISSUE_TEMPLATE/
│   │   ├── bug.yml
│   │   ├── feature.yml
│   │   └── documentation.yml
│   ├── workflows/
│   └── pull_request_template.md
│
├── compose.yaml        # development stack (Sail)
├── compose.prod.yaml   # production stack
│
├── CONTRIBUTING.md
├── CODE_OF_CONDUCT.md
├── SECURITY.md
├── CHANGELOG.md
├── LICENSE
└── README.md
```

---

# GitHub Actions

CI should validate:

```text
PHP dependencies
Laravel application
PHP tests
Static analysis
Frontend dependencies
TypeScript
Frontend build
```

A second workflow, `release`, publishes the production image on every `v*` tag:
both architectures build on their own native runner, and the resulting manifest
lists are pushed to Docker Hub and GHCR. See Deployment above.

Additional workflows may later be introduced for:

- Dependency updates
- Security checks
- Deployment

---

# Security

Security vulnerabilities should not be reported through public GitHub Issues.

A `SECURITY.md` file should define the private vulnerability reporting process.

---

# Code of Conduct

The repository should include a `CODE_OF_CONDUCT.md`.

---

# Development Philosophy

The project should favour:

- Simple architecture
- Explicit domain concepts
- Testable application logic
- Laravel conventions
- Minimal unnecessary abstractions
- Clear separation of concerns
- Backwards compatibility where practical
- Documentation of important architectural decisions

Avoid abstractions purely for architectural appearance.

---

# Future Features

Potential future functionality:

- OCR
- Advanced full-text search
- QR codes
- Barcode support
- Mobile/PWA interface
- Direct mobile scanning
- Automatic document classification
- Automatic metadata extraction
- Duplicate detection
- Document versioning
- Audit log
- S3/MinIO storage
- Meilisearch
- OpenSearch
- Webhooks
- Import/export
- Automatic backups
- Retention policies
- Document sharing
- Public API expansion
- Advanced Workspace administration
- Usage analytics

The roadmap describes potential future functionality and is not an implementation requirement unless explicitly promoted into the current scope.

---

# Implementation Priorities

When using this README as an implementation specification, prioritize:

```text
1. Laravel foundation
2. Workspace + Users
3. Configurable physical organization
4. Documents + metadata
5. Uploads / scans / photos
6. Search with Scout
7. Usage + Limits
8. React/Inertia UI
9. Tests
10. Docker + documentation
```

Do not implement every future feature before the core domain is stable.

## Preserve Configurability

Do not hard-code a specific physical filing system.

The application must support configurations such as:

```text
Cover → Letter → Position
```

and:

```text
Year → Cover → Position
```

without changing the database schema.

## Preserve Workspace Isolation

Every Workspace-owned resource must be scoped correctly.

Never trust a Workspace ID supplied directly by the client without validating the user's membership or authorization.

## Keep Domain Logic Out of Controllers

Complex business logic belongs in Actions or dedicated application/domain components.

## Avoid Premature Abstraction

Do not create repositories, services, interfaces or factories without a real architectural reason.

## Keep Optional Infrastructure Optional

The initial application should not require:

```text
Redis
Meilisearch
S3
MinIO
OCR
```

unless a feature genuinely requires them.

A minimal installation should work with:

```text
Laravel
MySQL
Local filesystem
Scout database-compatible engine
```

---

# License

Archivum is licensed under the [Elastic License 2.0](LICENSE) (ELv2).

You are free to use, modify, and self-host Archivum, including in production. The only restriction is that you may not offer Archivum to third parties as a hosted or managed service (SaaS).