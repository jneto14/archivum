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

# OCR

OCR is an optional subsystem.

Flow:

```text
Upload PDF/Image
        ↓
Store attachment
        ↓
Queue OCR job
        ↓
Extract text
        ↓
Store OCR text
        ↓
Re-index Document
```

OCR should run asynchronously.

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

Additional workflows may later be introduced for:

- Releases
- Docker images
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

TBD