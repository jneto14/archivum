# Attachment text extraction

Uploaded attachments have their text extracted in the background, so a document
can be found by what is written on the page and not only by its title.

**OCR is the fallback, not the first move.** A PDF that was born digital — an
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

The `archivum.ocr` block in `config/archivum.php`:

| Variable | Default | What it decides |
| --- | --- | --- |
| `OCR_ENABLED` | `true` | Switches the whole thing off |
| `OCR_LANGUAGES` | `por+eng` | Tesseract language codes, most common first |
| `OCR_MIN_TEXT_LENGTH` | `100` | Characters a PDF's text layer must yield before it is believed. Below this it is treated as a scan — scanned PDFs often carry a few stray characters from a header or a stamp, which is not a text layer |
| `OCR_MAX_PAGES` | `20` | Pages rasterized per attachment. OCR costs roughly a second of CPU per page |
| `OCR_DPI` | `300` | Rasterization resolution. Tesseract is trained around 300 and degrades below it |
| `OCR_TIMEOUT` | `120` | Seconds any single binary call may run |
| `OCR_MIN_WORD_CONFIDENCE` | `60` | Confidence, 0-100, a word must carry to be kept. Tesseract scores every word it reads; printed text scores 91-96 here, while ink carrying no text at all comes back between 0 and 63 |
| `OCR_MIN_CONFIDENT_WORD_RATIO` | `0.3` | Share of a page's words that must clear that floor before the reading is stored at all |

An installation without the binaries still works. Extraction records itself as
unavailable on the attachment and the document page says so, rather than failing
uploads or silently doing nothing.

## Where the state shows up

Twice, for two different readers. The attachment carries the text and its
status, shown on the document page next to the file it belongs to. Each
extraction is also a `Task`, so the workspace's Tasks page lists them alongside
exports and bulk moves — which is where an admin can see a failure and retry it
without opening documents one at a time.

Unlike the other task types, extraction takes no per-workspace lock: it is
scoped to a single file, so several run concurrently. The Tasks page is
paginated for the same reason — one row per uploaded file adds up.

## Failure modes

They are not all the same, and the job treats them differently:

| What went wrong | What happens |
| --- | --- |
| The file is not what it claims — a truncated upload recorded as a PDF | Recorded as failed and **not** retried. Three more attempts would fail identically and fill `failed_jobs` with something no operator can act on |
| The disk blipped, or a binary died | Rethrown, so the queue retries it |
| The job was killed around `handle()` — a timeout, a missing model | `failed()` records it, so the attachment and its task do not sit on "processing" forever |
| Neither a PDF nor an image | Recorded as *skipped*. Nothing is wrong with the file; extraction just does not apply |
| The binaries are absent, or OCR is off | Recorded as *unavailable* |
| The page was read, but almost none of it confidently | Recorded as *poorly read*, with no text stored. Not retried: the same file reads the same way |

A corrupt upload never fails the upload request, which matters on an
installation running the `sync` queue driver where the job runs inline.

## Reading a file again

Extraction runs once, when a file is uploaded. Everything about the reading is
therefore fixed at that moment: the pipeline of the day, the confidence floors
then in force, the language packs then installed. Change any of them and the
archive filed before the change keeps its old reading, searches differently
from anything filed after, and never appears on the review queue.

So any stored attachment can be read again, and the second reading **replaces**
the first rather than being merged into it. `markOcrProcessing()` is what
guarantees that: it clears the text, the counts, the error, the fingerprint,
the duplicate match and `ocr_reviewed_at` before the engine is asked anything.
Every path into extraction goes through it, so none of them can forget — and
the last two are the ones that matter, because a fingerprint of text that no
longer exists still flags duplicates, and a verdict left standing marks a
reading nobody has seen as reviewed.

That a confirmed reading goes back on the queue is deliberate. The person
answered a question about text that has been thrown away; the new text is a new
question (see [Every reading is confirmed by a person](#every-reading-is-confirmed-by-a-person)).

**One file at a time.** The button next to a file on its document page, open to
anyone who may edit the attachment — the same people who can already discard
its reading outright. It creates the ordinary task row an upload creates, so it
is visible and retryable on the Tasks page like any other extraction.

**The whole workspace.** The button on the Tasks page, for admins. One task row
stands for the sweep — a row per attachment would bury the page under however
many files the archive holds — and the extractions are batched beneath it, with
the row reporting `n of m files read` while it runs. A failed sweep is not
retried from that row: what it leaves behind is however much of the archive it
did read, and the answer is a fresh run, narrowed from the console to whatever
the first one missed.

**From the console**, for an operator upgrading an installation:

```shell
php artisan ocr:reextract --dry-run              # how many, before committing hours of CPU
php artisan ocr:reextract --unscored             # only what predates the confidence filter
php artisan ocr:reextract --status=failed        # only what fell over
php artisan ocr:reextract --workspace="Arquivo" --limit=500
```

Filterable rather than all-or-nothing because on a large archive this is hours
of CPU and the cases worth redoing are usually narrow. `--unscored` is how an
archive predating 0.4.0 is found: those attachments have no `ocr_word_count`,
because nothing was recording one.

A sweep is pushed onto its own queue, `OCR_BULK_QUEUE` (`ocr-bulk`), and the
worker is started with `--queue=default,ocr-bulk`. A worker drains its queues
left to right, so re-reading ten thousand scans never gets in front of the file
somebody just uploaded and is standing there waiting for. One sweep at a time
per workspace, held by a lock, so a second cannot reset the attachments the
first is part-way through.

## Timeouts

Three numbers have to stay in order, or a long OCR run is handed to a second
worker and the work happens twice:

```text
queue retry_after  >  worker --timeout  >=  job timeout  >=  max_pages × ocr.timeout
      3000                 2700                2700               2400
```

All of them derive from `OCR_MAX_PAGES` and `OCR_TIMEOUT`, so raising the page
cap moves the whole chain. `QueueTimeoutTest` fails if it stops holding.

## Believing it

Handwriting is read badly — the shipped models are trained on printed text, and
there is no handwriting model — so something has to stand between what Tesseract
returns and the database. Unlike suggested values and learned vocabulary, both
of which wait for a person, the extracted text is stored, mirrored onto the
document, indexed for search and reduced to a duplicate fingerprint with nobody
in the way.

Tesseract already scores every word it reads. Output is asked for as TSV rather
than plain text — the same recognition pass at the same cost — and the score
comes with it, so a guess no longer arrives indistinguishable from a reading.

**Words are filtered one at a time, not pages.** A printed form filled in by
hand is the common case in an archive, and the right outcome there is to keep
the printed labels and lose the scrawl: a value is recognised by the words in
front of it, so the labels are precisely what must survive. An all-or-nothing
page decision would throw them away along with the handwriting.

Line structure is rebuilt from the TSV's line columns rather than dropped, for
the same reason. Text reassembled as one long run of words would join the end of
one line to the start of the next and invent labels nobody wrote.

A refused word leaves **two spaces** rather than nothing. Removing it outright
closes the space it occupied, and a value's end is decided by adjacency — groups
one space apart are one value — so dropping an unreadable dash out of
`3 — 49051 242344062 1165797` offered four unrelated numbers as a single tax
number. The wider gap says what happened, and costs nothing elsewhere: every
other reader of this text either collapses whitespace or splits on it.

**Then a floor for the page.** Where almost nothing survives — a sheet of pure
handwriting — the few words that scored well are as likely to be noise that
happened to look like a word. The attachment is recorded as *poorly read* and no
text is stored, which keeps it out of the search index, out of the document's
mirror and out of the fingerprint. That is distinct from a blank page, which was
read perfectly and simply has nothing on it.

**A blank page is told apart by its layout, not its word count.** Tesseract
lays out blocks, paragraphs and lines before it recognises anything, so a page
of handwriting comes back with lines on it and not one readable word, while a
blank sheet comes back with no layout at all. Counting only words makes those
two the same answer — and a photographed page of handwriting was duly recorded
as a blank page that had been read perfectly, which is the exact case any of
this exists for. Across
a multi-page scan the counts add up rather than being averaged per page, so one
unreadable page does not condemn the other nineteen.

### Every reading is confirmed by a person

Confidence answers how sure the engine was of each word. It does not answer
whether the reading is **right** — a confident misreading scores as well as a
correct one, and no threshold can tell those apart. Only somebody looking at the
page can.

So the review queue puts the extracted text itself in front of a person,
verbatim and preformatted, beside the file it came from. Two answers:

- **Keep it.** The reading stands, and the attachment leaves the queue.
- **Throw it away.** `ocr_text` is deleted, the fingerprint with it, and the
  attachment is recorded as poorly read. Not flagged — deleted. The text is what
  feeds the search index and the duplicate comparison, so a reading nobody
  believes has to stop being one; flagging it would leave it doing its damage.

A page the engine refused itself has no text to judge and only wants
acknowledging, which is the same answer with nothing to weigh.

`ocr_reviewed_at` records that somebody answered, either way. It is a separate
column from the status because the status is what happened to the file and goes
on being shown on the document page, while this only says the question has been
put and answered.

## What is made of the text

Two things, both once extraction completes and both on the queue, so nothing is
computed while somebody waits for a page.

**A fingerprint, to catch a document filed twice.** The text is reduced to a
64-bit SimHash (`TextFingerprint`) and compared against every other attachment
in the same workspace; anything within `INTAKE_DUPLICATE_MAX_DISTANCE` bits is
recorded on the attachment as the copy it appears to be of, and the document
page says so with a link and a way to dismiss it. A plain hash would be useless
here — the case worth catching is one page scanned twice, and OCR reads a few
characters differently on each pass.

Shingles carrying a number count for four, which is what makes the threshold
work at all: two invoices from the same supplier are the same page of prose with
a different number, a different date and a different total, and measured plain
they sit as close as a rescan does.

**Suggested values, to save typing them.** `SuggestDocumentMetadata` reads
values out of the text. Nothing is written without being accepted. See
[documents.md](documents.md) for how a suggestion decides which key it belongs
in.

A value is recognised by the **words in front of it**, not by its format:
"VAT registration 501 234 567" is a tax number because of what introduces it,
which needs to know nothing about the country that issued it. That vocabulary
lives in `lang/{locale}/intake.php`, so adding a language to `archivum.locales`
and translating that file is the whole of teaching this a new country — there is
no list of countries in the code. Every configured language is searched at once,
because an archive holds an English invoice and a Portuguese receipt side by
side, and month names come from `intl` for the same reason.

Two kinds need no vocabulary, because their formats are not national: a date,
and a number written to exactly two decimals (largest wins — an invoice's total
is no smaller than the lines above it). The one thing left that a country
decides is whether `03/04/2026` is March or April, which is
`INTAKE_DATE_ORDER`.

The cost of reading by label is a value printed with no label at all, which is
rare — and it fails by saying nothing rather than by suggesting something wrong.

`archivum:backfill-suggestions` reads documents extracted before any of this
existed; `--all` re-reads everything, for when these heuristics improve.

### There is no list of the things an archive holds

A date and an amount are the only kinds named in the code, and they earn it by
being found by their shape rather than by any word. Everything else is
**whatever the archive itself files**: metadata is free-form key/value pairs, so
a workspace that created "Nº de apólice" has already said what that field is,
more accurately than any list could. The metadata key *is* the kind
(`IntakeVocabulary`).

This replaced a fixed pair — a tax number and a vehicle registration, each with
a hand-written rule for what its value may look like. `strlen >= 8 && digits >=
6` is a Portuguese assumption wearing a general face, and an archive of
insurance policies, clinical records or building permits got nothing out of it
at all.

**The shape is learned too.** What a value of some kind looks like is derived
from the ones the workspace has already filed under that key: how long they are,
whether they carry letters, whether they are purely numeric (`ValueShape`). It
is counting characters over data that is already there — no model, no country
list — and it gets sharper as the archive grows. Two rules are not derived,
because they are what makes any of it safe: a value must carry a digit, and must
be at least five characters. That is what stops a label sitting in front of "não
aplicável" adopting those two words.

The same derivation is a filter. A key whose filed values do not describe one
kind of thing — "Observações", or anything else free-text — has no shape to
check a reading against, so **nothing is learned for it**. Without that, the
reader would start lifting sentences off pages.

**And it is what says where a value ends.** What follows a label is groups of
letters and digits joined by spaces, dots or dashes, because that is what "501
234 567" and "12-AB-34" look like on a page. The cost of allowing the space is
that a value with prose after it on the same line takes the prose with it: "o
veículo com a matrícula 00-EV-80 deverá realizar" reads out as *00-EV-80 deverá
realizar*, which is not shaped like a registration. So the run is offered to the
shape whole, then a group shorter, and a group shorter again, and the longest
one that fits wins — nine digits where a tax number is nine digits long, six
characters where a registration is six, out of the same sentence and with
nothing written down about either.

A value is suggested **as the page wrote it**, spacing and punctuation included:
*501 234 567*, not *501234567*. Stripping separators was a rule one of the two
removed readers carried and the other did not, and a policy number written
*POL-2026-0044* needs its dashes. A space is easy to delete; a dash that was
never offered is not.

The keys in `lang/{locale}/intake.php` remain, as a **seed**. Without them a new
archive would read nothing until somebody had filled the same field in by hand
on three documents, which is a feature for saving typing that requires the
typing first.

### Vocabulary an archive learns for itself

The shipped words cannot cover every way a document names things. A page
writing "Steuernummer", or a field nobody thought of, is simply not read, and
the only fix would be somebody noticing, reporting it and waiting for a release.

But the archive is already holding the answer. Whenever a user fills in a field
the reader missed, it keeps both the extracted text and the value they decided
belonged in it. Find that value in that text, look at the words immediately in
front of it, and the page has said what it calls the thing.

**It learns one document at a time, when the signal exists.** That is the moment
somebody saves metadata on a document, and the moment a document's text finishes
extracting — `LearnDocumentIntakeLabels`, on the queue. This used to be a weekly
sweep of every document in every workspace, which was wrong twice over: a user
correcting a field waited up to a week to be asked about it, and the cost grew
with the size of the archive rather than with how much of it changed.

Counting incrementally is why `intake_label_documents` exists. A recount cannot
double-count; an increment can, the second time the same document is edited. So
**which** documents evidence a phrase is recorded rather than how many, which
makes re-reading a document idempotent by construction — and gives an admin the
documents themselves to judge a candidate by.

The key somebody typed is recorded on the candidate at the same moment, in
`intake_labels.field`. A learned kind has no name the application ships — it is
a normalised key — so it is shown as the workspace spells it. Working that out
later would mean sampling the archive for the spelling, and a key that has not
been filed in the last few hundred documents falls out of that sample: thirty
parking fines in an archive of hundreds, and the interface starts asking about
"auto_n" instead of about "Auto nº".

`archivum:learn-intake-labels` survives as the backfill, for an archive that was
filled before any of this existed. It is not scheduled. Nothing had to be
captured up front for it: `ocr_text` and `metadata` are both retained, so an
archive that has been running for years can be mined the first time it is run.

**A bad label is not a private mistake.** It makes the reader confidently wrong
across every document in the workspace, and a word common in prose would match
prose. Four things stand in the way:

| | |
| --- | --- |
| A consistent field | Only keys whose filed values describe one kind of thing are mined. A free-text field has no shape to check a reading against; a date and an amount are read by their shape and have nothing to learn. |
| A support threshold | A phrase must recur across `INTAKE_LABEL_MIN_SUPPORT` documents in the same workspace (3 by default) before it is offered at all. Applied when candidates are read, so raising it takes effect on what is already mined. |
| A length floor | The word touching the value must be long enough to be a word, which keeps "de" and "nº" from being proposed alone while leaving them usable inside a longer phrase. |
| Approval | Nothing enters the vocabulary unaccepted. Candidates wait on the review queue for an admin to answer, with the documents that taught them. |

A rejection is recorded rather than simply not accepted, because the mining that
proposed a phrase once will propose it again from the next document that writes
it — without a no that sticks, an admin would spend the rest of the archive's
life turning down the same word. Retiring a label already in use is the same
write, for the same reason.

Accepted labels are read alongside the ones in `lang/{locale}/intake.php`, and
are **scoped to the workspace that accepted them**: a phrase mined from one
archive's suppliers can be meaningless in another's, so a label that turns out
to be a bad one degrades the readings of one workspace and of nobody else.

Answering either way starts `RereadWorkspaceSuggestions`, which reads the
workspace's already-extracted documents again. Without it, accepting a word
would only ever have applied to documents filed afterwards — the opposite of why
anybody accepts one, since the point is the archive that is already there.

## The review queue

Both of the above are found minutes after a document is registered, by which
time whoever registered it is filing the next one — and there is no bulk
registration, so an archive is built one document at a time. Waiting for each
document's own page to be revisited would mean nothing is ever confirmed.

So the findings are collected on **To review** (`documents.review`), a
workspace-wide queue: one row per document, its suggested values ticked by
default, applied or dismissed in a click. Scans that could not be read are
listed below it, then flagged duplicates, and below those — **for workspace
admins only** — the words the archive is proposing to read by. The sidebar carries the count, which costs one query on
every request and is the reason the queue is used at all; the label half of it
is counted only for the admins who are shown that section, so nobody is badged
towards work they cannot do.

What is stored is only *what the text said* — kind and value, on
`documents.metadata_suggestions`. Which field each value belongs in, and whether
that field is still empty, are worked out when the suggestions are read: both
change after extraction ran, and a queue offering to fill a field that already
has a value in it is one people stop trusting. The column is emptied when the
document is reviewed, and on any edit that leaves nothing to suggest.

## Searching it

See [search.md](search.md) for the two modes, and why the extracted text is
mirrored onto the document rather than only living on the attachment.
