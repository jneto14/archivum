# Physical organization

The part of Archivum that has to bend to how an office already files things,
rather than asking it to change.

Nothing in the schema knows what a *cover*, *folder*, *letter*, *year*,
*cabinet*, *drawer*, *shelf* or *position* is. They are configuration.

```text
Cover
└── Letter
    └── Position
```

Another installation:

```text
Cabinet
└── Drawer
    └── Folder
        └── Position
```

Another:

```text
Year
└── Document Type
    └── Position
```

All three run on the same tables, with no schema change.

## Schemes

An `OrganizationScheme` is how one workspace files its paper.

```text
Traditional Archive
  Level 1: Cover
  Level 2: Letter
  Level 3: Position
```

**A workspace has at most one scheme.** The tables would carry more, and an
earlier version of this document said they could, but the product decision is
one: two schemes over one physical archive means every document has two answers
to "where is it", and the second one is always the stale one.

## Levels

An `OrganizationLevel` is one tier of the hierarchy. It defines:

| | |
| --- | --- |
| Name | What people call it — "Cover" |
| Key | A stable identifier for rules to match on |
| Position | Its depth, 1-indexed, within the scheme |
| Capacity | How many children fit under one parent, or unlimited |
| Value strategy | How a new node's value is generated |
| Display settings | Presentation only |

Two value strategies:

- **Sequential** — zero-padded numbers: `001`, `002`, `003`.
- **Alphabetical** — spreadsheet-style letters: `A`, `B`, … `Z`, `AA`. A level
  using it is capped at 26 unless it is allowed to run to two letters.
- **Manual** — the value is typed. A manual level with no existing node and no
  rule supplying a value cannot auto-file, and says so rather than inventing one.

Only the last level in a scheme can be deleted, and only while it holds no
nodes. Removing a level from the middle would orphan everything below it.

## Nodes

An `OrganizationNode` is an actual physical place.

```text
Cover 001
├── A
│   ├── 1
│   ├── 2
│   └── 3
├── B
│   └── 1
└── C
```

Stored as a generic tree: each node names its level and its parent. A document's
location renders as the values from root to leaf:

```text
001-A-3
```

A node with documents currently located at it cannot be deleted. To empty it,
migrate its documents to another node first — that is what the bulk move does,
on the queue, tracked as a `Task`.

## Rules

Rules say where a document *should preferably* go.

```text
Document Type: Invoice  →  prefer section A
```

A rule matches on a key/value pair — most often the document type — and names a
target level plus the preferred value at that level. Matchers are unique within
a scheme, and a rule may only target a level of its own scheme.

Rules are recommendations, not constraints. A user can always file a document
somewhere else.

## The engine

```text
ApplyOrganizationRules      which rule, if any, matches this document
        ↓
FindAvailableLocation       walk the scheme, level by level
        ↓
OrganizationNode            the leaf the document is filed at
```

`FindAvailableLocation` resolves each level in turn:

1. If a rule names a preferred value at this level, use that node — creating it
   if this is the first document to be filed there.
2. If the preferred node is full, or there is no rule, use the first sibling
   that still has room below it.
3. If none has room, create a new node at this level.

"Room below it" means the child level has not reached its capacity under that
node. Capacity is what makes `001-A` fill up and `001-B` open.

The result is a suggestion. The document show page offers it; the user files the
document wherever they like.

## Location history

Physical assignments are tracked separately from the document:

```text
Document #123

2026-08-22   001-A-3
2027-03-14   014-C-2
```

The current location is the newest assignment. Keeping the history is what makes
a document findable after someone has reorganised the archive around it.
