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

## Searching it

See [search.md](search.md) for the two modes, and why the extracted text is
mirrored onto the document rather than only living on the attachment.
