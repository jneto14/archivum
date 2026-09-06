---
paths:
  - 'app/Services/Ocr/**'
---

# Ocr

## OCR output must stay TSV — the per-word confidence is the only guard on the database
`TesseractEngine` asks for `configFile = 'tsv'`, not plain text. Same recognition pass, same cost, but it carries a confidence per word — and that score is the only thing between a page of handwriting and a search index full of words nobody wrote (ARC-118). Switching back to plain text output silently removes the guard.

**Dropping a word must leave a wider gap, not close the space.** `TesseractTsv` writes two spaces where a word was refused. Removing a word silently encloses its neighbours, and `SuggestDocumentMetadata` decides where a value ends by adjacency — groups one space apart are one value. Dropping a scan's em dash out of `3 — 49051 242344062 1165797` (it scored 29) offered four unrelated numbers as one tax number. This shipped; do not simplify it back to a filter over a list of words.

Filter word by word, never per page: a printed form filled in by hand must keep its printed labels, because a value is recognised by the words in front of it. Rebuild line structure from the TSV line columns too, or the end of one line joins the start of the next and invents labels.

A blank page counts as fully confident (`confidentRatio()` returns 1.0 at zero words), not as a total failure — otherwise every blank sheet reports itself unreadable. Across pages the counts add rather than average, so one bad page in a twenty-page scan does not condemn the rest.

The `match` on `OcrStatus` in `ExtractAttachmentText` lists every case on purpose: adding a status without deciding what it means there is meant to fail PHPStan at build time.
