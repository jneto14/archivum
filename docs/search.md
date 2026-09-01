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

## The two modes

MySQL's full-text index matches **whole words**. That is the right default for a
large archive and the wrong answer for someone typing a prefix, so the search
offers both:

| Mode | Attachment text | Title |
| --- | --- | --- |
| **Whole words** (default) | Whole-word match, natural language mode | Substring |
| **Word starts with** | Prefix match, boolean mode with a trailing wildcard | Substring |

Both stay on the index, so neither scans the stored pages. The cost is that
neither matches the *middle* of a word: `atura` will not find `fatura`.

In the broader mode every typed term must appear somewhere — title or text —
because ORing them returns most of the archive as soon as someone types three
words.

Punctuation is a separator, not syntax. Boolean mode reads `+ - * " ( ) ~` as
operators, so `edp-2026` is split into two terms rather than being read as
"edp but not 2026".

## Filters

Scout handles the text. Structured filtering stays in the relational database,
where it belongs:

```text
Search:  BMW 320d
Filters: type = Invoice
         year = 2026
```

Filter state lives in the URL, so a filtered view can be linked, bookmarked and
reloaded. The documents index is paginated with numbered pages for the same
reason — a position in a large result set should survive a refresh.

## Cost

The documents index does not issue more queries as it grows.
`QueryBudgetTest` asserts that: it measures the page, adds sixty documents, and
requires the query count to be identical. An N+1 that only appears with data is
otherwise invisible on a fixture of three rows.
