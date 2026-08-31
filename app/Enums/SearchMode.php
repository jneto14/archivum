<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How the free-text part of a document search is matched.
 *
 * The two exist because one query string has to serve two very different
 * haystacks. A document title is short, so `LIKE '%term%'` over it is both
 * cheap and forgiving. The text extracted from attachments is not: it is
 * indexed with MySQL FULLTEXT, which matches whole words only — so searching
 * "fatur" finds a document *titled* "Fatura" but not one whose scan says it.
 */
enum SearchMode: string
{
    /**
     * Whole words inside attachment text, substrings in the title. Uses the
     * full-text index in natural language mode, and is the default.
     */
    case Exact = 'exact';

    /**
     * Also matches the start of a word inside attachment text, so "fatur"
     * finds "faturas". Still uses the full-text index — boolean mode with a
     * trailing wildcard — so it stays fast on a large archive. It cannot match
     * the middle of a word: "atura" will not find "fatura".
     */
    case Broad = 'broad';
}
