# Scope and roadmap

What Archivum is for, what it deliberately is not, and what might come next.

## In scope

The core domain, all of it built:

```text
Workspaces, users, memberships and roles
Documents, document types, configurable metadata, tags
Digital attachments, with background text extraction
Physical locations and location history
Configurable organization schemes, levels, nodes and rules
Search through Laravel Scout
Usage and limits
Authentication and authorization
A React/Inertia interface
Self-hosting: a production image, a queue worker and a scheduler
```

## Out of scope

**SaaS billing, subscriptions and external provisioning.** Limits exist as
configuration — `storage_bytes`, `users`, `documents`, `attachments` — and
there is no pricing logic anywhere near them. Archivum is something you run,
not something you rent.

The [license](../LICENSE) reflects that: you may self-host it in production,
but not offer it to third parties as a managed service.

## Principles

These are the calls that shaped the code, and the ones to preserve.

### Preserve configurability

Do not hard-code a filing system. `Cover → Letter → Position` and
`Year → Cover → Position` must both work without a schema change. If you find
yourself writing the word "drawer" in a migration, something has gone wrong.

### Preserve workspace isolation

Every workspace-owned resource is scoped. Never trust a workspace id supplied
by the client without validating membership — and remember that route-model
binding resolves each parameter independently, so a controller receiving two
bound models must check they belong together.

### Keep domain logic out of controllers

A controller authorizes, resolves, delegates and renders. Multi-step writes and
business rules belong in an Action, where they can be tested directly.

### Avoid premature abstraction

No repositories, no generic DTO layer, no interface without a second
implementation or a real external boundary. Indirection added for symmetry is
cost without benefit.

### Keep optional infrastructure optional

A minimal installation should need only:

```text
Laravel
MySQL
The local filesystem
Scout's database engine
```

Redis, Meilisearch, S3, MinIO and the OCR binaries are all things Archivum uses
when they are there and works without when they are not. The production stack
ships Redis because it costs nothing to include in a compose file and makes
cache and sessions cheaper — not because the application requires it. Text
extraction records itself as *unavailable* rather than failing when the
binaries are missing.

## Possible future work

Not commitments. Nothing here is an implementation requirement unless it is
explicitly promoted into scope and given an issue.

- Barcode support alongside QR codes for physical containers
- QR codes on storage containers, scanning through to the location
- Mobile/PWA interface, and scanning directly from a phone
- Automatic document classification and metadata extraction
- Duplicate detection
- Document versioning
- S3/MinIO storage in the shipped configuration
- Meilisearch or OpenSearch as a Scout engine for larger archives
- Webhooks
- Import from other document management systems
- Automatic backups
- Retention policies
- Document sharing outside the workspace
- A real HTTP API — see [api.md](api.md)
- Usage analytics

If you want one of these, open a feature request and say what you would use it
for. The use case is the part that decides.
