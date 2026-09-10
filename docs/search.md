# Search

Search goes through Laravel Scout, so the engine is an implementation detail the
application does not depend on. The shipped configuration uses Scout's
`database` engine against MySQL; Meilisearch or another engine can be swapped in
without touching application code.

```dotenv
SCOUT_DRIVER=database
```

This one is worth setting explicitly. Scout's own default is `collection`, which
filters in PHP and never touches the full-text index this application builds —
an installation that leaves it unset gets results that look plausible and are
produced entirely the wrong way. The install recipe in
[deployment.md](deployment.md) names it for that reason, and `InstallRecipeTest`
checks it still does.

## What is searched

Two haystacks, joined by one query string:

| | |
| --- | --- |
| The document's **title** | Short. A substring match over it is cheap and forgiving |
| The **text extracted from its attachments** | Potentially thousands of words per file, indexed `FULLTEXT` |

Extracted text is stored per attachment *and* mirrored onto the document as a
single concatenated `ocr_text` column. The mirror is not redundancy for its own
sake: Scout's `database` engine searches columns on the searchable model's own
table and cannot traverse a relation, so without it text held on the attachments
would never be matched by a document search.

Deleting an attachment rebuilds that mirror, so a removed scan stops being
findable by its contents.

## The four modes

The mode says how the words someone typed are combined. It is a question about
intent — "every word", "the words together" — and deliberately not a question
about the index underneath, which is what the two modes it replaced were.

| Mode | What it matches |
| --- | --- |
| **All words** (default) | Every term, in the title or the text, in any order |
| **Any word** | One term is enough |
| **Exact phrase** | The terms adjacent and in order |
| **Title only** | Every term in the title; the text is not read |

Three of the four search the title **and** the extracted text together. That is
the part the old modes never said: they named how the *text* was matched while
the title quietly matched a substring in both, so `voice` found a document
titled `Invoice` under a mode labelled "Word starts with".

Underneath, each mode is built from the same two clauses — a substring `LIKE`
on the title, and a boolean-mode predicate on `ocr_text` — combined
differently. One set of parts is what keeps the modes describing the same
search rather than drifting into matching different columns from one another.

### What is not a mode

**The trailing wildcard.** `fatura` also finds `faturação`, and this is now
behaviour rather than a choice. Measured on a purpose-built corpus, offering it
as a mode bought almost nothing: `contrato` does not begin matching
`contratada`, nor `seguro` `segurado`. What it does add is longer words sharing
a root, which in an archive is wanted. Exact phrase is the exception — a phrase
matching prefixes would not be the phrase that was typed.

**The middle of a word.** No mode matches it: `atura` will not find `fatura` in
scan text. Matching an infix means `LIKE '%term%'`, which cannot use the index
and would scan every stored page. The title is short enough to afford it, and
does.

**Terms shorter than three characters.** InnoDB ignores tokens below
`innodb_ft_min_token_size`, so the title's `LIKE` clause is what rescues them.
Every mode that reads the title therefore still finds `IA`.

Punctuation is a separator, not syntax. Boolean mode reads `+ - * " ( ) ~` as
operators, so `edp-2026` is split into two terms rather than being read as
"edp but not 2026". The same sanitising is what keeps `%` and `_` out of the
`LIKE` patterns.

### Links written before the rename

Filter state lives in the URL and a filtered view is meant to survive being
bookmarked, so `mode=exact` and `mode=broad` still resolve — both onto **All
words**, which is what `broad` already was and what `exact` was trying to be.
A mode that is neither current nor legacy is rejected rather than silently
searched some other way.

## Filters

Scout handles the text. Structured filtering stays in the relational database,
where it belongs:

```text
Search:  BMW 320d
Filters: type = Invoice
         year = 2026
         location = 001-A
```

Filter state lives in the URL, so a filtered view can be linked, bookmarked and
reloaded. The documents index is paginated with numbered pages for the same
reason — a position in a large result set should survive a refresh.

The location filter is what the physical archive links into, and it reads the
tree rather than one node: filtering by a cabinet answers with the documents on
every shelf below it. It matches a document's *current* location, so one that
used to be there and has since been moved on does not come back. A node from
another workspace matches nothing, rather than being dropped — dropping it would
answer "what is in that location" with the whole archive.

## Order

The index comes back most recently registered first, and can be read by title,
document date, type, or registration date instead. Like the filters, the choice
travels in the query string, so a sorted view can be linked and returned to.

Every order ends in `documents.id`, and that part is not cosmetic. `paginate()`
runs a fresh query per page with a different `OFFSET`, so an order that leaves
ties — a date many documents share — lets a document be shown on two pages or on
none while somebody clicks through. The list appears to lose and duplicate
records. Only the columns `SearchDocuments::sortColumns()` declares ever reach
`orderBy`; anything else falls back to the default rather than being refused, so
a bookmark to a column that has since been renamed still opens the page.

A document's physical location is not among the orders. It is the latest of its
assignments, resolved through a node's ancestors into a path assembled in PHP,
and no single column holds it.

## Cost

The documents index does not issue more queries as it grows.
`QueryBudgetTest` asserts that: it measures the page, adds sixty documents, and
requires the query count to be identical. An N+1 that only appears with data is
otherwise invisible on a fixture of three rows.
