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

A corrupt upload never fails the upload request, which matters on an
installation running the `sync` queue driver where the job runs inline.

## Timeouts

Three numbers have to stay in order, or a long OCR run is handed to a second
worker and the work happens twice:

```text
queue retry_after  >  worker --timeout  >=  job timeout  >=  max_pages × ocr.timeout
      3000                 2700                2700               2400
```

All of them derive from `OCR_MAX_PAGES` and `OCR_TIMEOUT`, so raising the page
cap moves the whole chain. `QueueTimeoutTest` fails if it stops holding.

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

**Suggested values, to save typing them.** `SuggestDocumentMetadata` reads dates,
currency amounts, tax numbers and vehicle registrations out of the text. Nothing
is written without being accepted, and the heuristics are precision-first: an
amount needs a currency marker beside it, a tax number needs its check digit to
agree. See [documents.md](documents.md) for how a suggestion decides which key it
belongs in.

## The review queue

Both of the above are found minutes after a document is registered, by which
time whoever registered it is filing the next one — and there is no bulk
registration, so an archive is built one document at a time. Waiting for each
document's own page to be revisited would mean nothing is ever confirmed.

So the findings are collected on **To review** (`documents.review`), a
workspace-wide queue: one row per document, its suggested values ticked by
default, applied or dismissed in a click. Flagged duplicates are listed below
it. The sidebar carries the count, which costs one query on every request and is
the reason the queue is used at all.

What is stored is only *what the text said* — kind and value, on
`documents.metadata_suggestions`. Which field each value belongs in, and whether
that field is still empty, are worked out when the suggestions are read: both
change after extraction ran, and a queue offering to fill a field that already
has a value in it is one people stop trusting. The column is emptied when the
document is reviewed, and on any edit that leaves nothing to suggest.

## Searching it

See [search.md](search.md) for the two modes, and why the extracted text is
mirrored onto the document rather than only living on the attachment.
