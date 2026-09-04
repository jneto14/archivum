# Documents

A `Document` is the *logical* document. Where its paper copy sits is a separate
concern, handled in [organization.md](organization.md).

```text
Invoice #FT2026/1234

Type:     Invoice
Date:     2026-08-20
Supplier: Example Lda
Vehicle:  12-AA-34

Tags: vehicle, 2026, maintenance
```

A document may carry any number of digital attachments:

```text
Document
├── scan.pdf
├── front.jpg
├── back.jpg
└── additional.pdf
```

The document itself assumes nothing about a physical storage structure.

## Document types

Types describe what a document *is*: Invoice, Contract, Insurance, Tax Document,
Receipt, Vehicle Document, Warranty. They are configured per workspace and are
independent of physical location.

A type is also what [organization rules](organization.md) most often match on,
which is how "invoices go in section A" is expressed without hard-coding either
concept.

Each type carries a `key` alongside its name — a stable identifier for rules to
match against, so renaming *Invoice* to *Supplier Invoice* does not silently
break the rule that files them.

## Dynamic metadata

Fields vary by type, so they are stored as JSON on the document:

```json
{
    "supplier": "Example Lda",
    "vehicle_registration": "12-AA-34",
    "amount": 1250.50
}
```

MySQL JSON is the initial implementation, and it is the right one while the
fields are open-ended and rarely filtered on. A field that turns out to be
queried constantly can be promoted to an indexed generated column later, when
there is usage to justify the cost.

There is deliberately no schema declaring which fields a type has. The keys are
whatever the workspace types, and a type's fields are only the keys its
documents already carry.

That is also how a suggestion read out of an attachment's text decides where to
go (see [ocr.md](ocr.md)): each kind of value it recognises carries a list of
alias names, and adopts a key already used by other documents of the same type
when one of them matches. A workspace whose invoices all say *total* is not
handed a second field called *amount*. With no match, the kind's default key is
used. A suggestion is only ever offered for a field that is still empty, and
only ever applied when somebody accepts it.

## Tags

Free-form labels, scoped to the workspace and unique within it. A tag is shared
by many documents through `document_tags`.

The tags page shows each tag's document count and when it was last used, which
is what makes an unused tag visible enough to delete.

## Attachments

The file itself lives on a filesystem disk; the database keeps the metadata. See
[storage.md](storage.md) for where files go and [ocr.md](ocr.md) for what
happens to their text.

Uploading several files at once is one operation: the batch is validated as a
whole against the workspace's attachment and storage limits, and rejected whole
if it would cross either. Storing files up to the ceiling and failing on the
rest would leave the user to work out which ones landed.

Deleting an attachment removes its extracted text from the document's searchable
mirror, so a removed scan stops being findable by its contents.

### Scanning a page

Two ways in, and which one the **Scan** button offers depends on whether the
device it is pressed on can open a camera at all. `mediaDevices` is undefined
outside a secure context, so a phone reached over plain HTTP reports no camera
however many it has — and that is the honest answer, since there is none this
page could open.

**With this device's camera.** A live viewfinder inside the app, with the page
outline drawn over the picture while you aim. Detection runs on a downscaled
copy of the frame — a guide only has to be roughly right, and at 1080p it would
be slower than the frames it is describing — while the shutter keeps the frame
at full resolution. Confirming a page returns to the viewfinder rather than to a
form, so several pages are shot in a row, and each one joins the same upload
queue a file chosen from disk does.

**With another device.** The QR pairing flow (`routes/capture.php`): the desktop
issues a signed, unauthenticated URL, and the phone that scans it uploads
straight to the document without ever signing in. This is what a phone that is
not a signed-in client gets, and it is reachable from inside the camera dialog
for anyone who would rather use their phone than the machine in front of them.

Both paths end in the same review step, which detects the page's corners, lets
them be dragged, and straightens whatever quad is left behind. What it refuses
is as load-bearing as what it accepts — see `isImplausibleDocument` and
[.ai/rules/lib.md](../.ai/rules/lib.md).

## Location

A document's physical placement is a history of assignments, not a field. The
newest row is the current location; the rest is where it used to be. See
[organization.md](organization.md).

The document page suggests where to file it and lists every location in the
workspace to pick from instead. A suggested location that does not exist yet is
only created once it is picked, so looking at a document leaves no empty
positions behind, and a location that is full is refused rather than offered.

The index reads the same relationship from the other end: it can be filtered by
location, which is how the physical archive links through to what is on a
shelf. See [search.md](search.md).

## Activity

Important operations are recorded through `spatie/laravel-activitylog`, scoped
to the workspace: document creation and edits, moves, membership and role
changes. The workspace activity page reads it, and a scheduled command prunes it
so it cannot grow without bound.

Work that happens on the queue attributes its activity to the user who triggered
it — a queue worker has no authenticated request, so the job resolves the causer
explicitly.
