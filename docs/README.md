# Archivum documentation

The reference documentation for people working on Archivum, or self-hosting it.

For what Archivum is and how to get it running, start at the [project README](../README.md).

## Contents

| Document | Covers |
| --- | --- |
| [architecture.md](architecture.md) | The stack, the request path, and where each kind of logic is allowed to live |
| [database.md](database.md) | The tables, how they relate, and the conventions every model follows |
| [workspace.md](workspace.md) | Workspaces, membership, roles, isolation, usage and limits |
| [documents.md](documents.md) | Documents, types, dynamic metadata, tags and attachments |
| [organization.md](organization.md) | The configurable physical filing system: schemes, levels, nodes and rules |
| [search.md](search.md) | Scout, the full-text index, and the two search modes |
| [storage.md](storage.md) | Where files live, and what the database keeps about them |
| [ocr.md](ocr.md) | Attachment text extraction: the pipeline, its binaries and its failure modes |
| [api.md](api.md) | The HTTP API surface, such as it currently is |
| [development.md](development.md) | Running the project locally, the checks, and the conventions |
| [deployment.md](deployment.md) | Running it in production: the image, the stack, upgrades and configuration |
| [roadmap.md](roadmap.md) | What is in scope, what is deliberately not, and the principles behind both |

## How this is written

These documents describe **what the code does**, not what it was once intended
to do. Where a design decision has a reason that is not obvious from reading the
code, the reason is written down next to it — that is most of the value here.

Repository-level conventions that an automated tool should follow live in
`.ai/rules/`, not here. Those are short, path-scoped, and load-bearing; this is
prose for humans.
