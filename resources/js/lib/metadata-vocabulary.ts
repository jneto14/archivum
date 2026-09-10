/**
 * Picking suggestions out of the workspace's metadata vocabulary while a row
 * is being filled in.
 *
 * The vocabulary itself is built on the server (`SuggestMetadataVocabulary`),
 * which has already decided that `NIF`, `nif` and `Nº Contribuinte` are one
 * field and which spelling the workspace writes most often. Everything here is
 * the narrowing that has to happen on every keystroke: matching what has been
 * typed, and leaving out what this document is already filing by.
 */

export type MetadataVocabularyEntry = {
    /** The spelling this workspace writes the field as, most used first across the list. */
    key: string;
    /** The document types this key has been filed on, used to rank rather than to filter. */
    documentTypeIds: string[];
    /** The values filed under it, most common first. */
    values: string[];
};

/**
 * Lowercase a string and strip its accents, so `Matrícula` and `MATRICULA` are
 * one word.
 *
 * Mirrors `IntakeVocabulary::fold()` on the server — the two have to agree, or
 * a key the server considers a duplicate is offered here as a new one.
 */
export function fold(value: string | null | undefined): string {
    if (typeof value !== 'string') {
        return '';
    }

    return value
        .normalize('NFD')
        .replace(/\p{Diacritic}/gu, '')
        .toLowerCase()
        .trim();
}

/**
 * Narrow a list of candidates to what has been typed.
 *
 * A substring match rather than a prefix one, because a key is often reached by
 * its second word — somebody looking for `Nº de apólice` types "apolice". The
 * ones that do start with what was typed are still ordered first, so the exact
 * thing being spelled out does not sit below a longer key that happens to
 * contain it.
 *
 * Candidates arrive ranked by how often the workspace uses them, and a stable
 * sort is what keeps that ranking inside each of the two groups.
 *
 * What is already in the field is never offered back. Without that, opening a
 * document whose rows are already filled in pops a list under every one of them
 * whose only entry is the value being looked at — the suggestions turn up
 * exactly where there is nothing to suggest, and the row being added, which is
 * the one that needed them, is the row they are missing from.
 */
function narrow(candidates: string[], typed: string): string[] {
    const query = fold(typed);

    if (query === '') {
        return candidates;
    }

    const matching = candidates.filter(
        (candidate) =>
            fold(candidate) !== query && fold(candidate).includes(query),
    );

    return [
        ...matching.filter((candidate) => fold(candidate).startsWith(query)),
        ...matching.filter((candidate) => !fold(candidate).startsWith(query)),
    ];
}

/**
 * The keys to offer for a metadata row.
 *
 * Keys already on this document are left out: the form writes one value per
 * key, so offering a second row the same key offers the user a way to lose
 * whatever they type in one of them.
 *
 * The selected document type ranks rather than filters. A key common on
 * invoices is noise on a contract, but a workspace whose contracts are all
 * filed by hand today would otherwise be offered nothing at all the first time
 * it files one — and the key that is "noise" is regularly the one being looked
 * for.
 */
export function suggestKeys(
    vocabulary: MetadataVocabularyEntry[],
    options: { typed: string; documentTypeId: string; usedKeys: string[] },
): string[] {
    const used = options.usedKeys.map(fold);

    const available = vocabulary.filter(
        (entry) => !used.includes(fold(entry.key)),
    );

    const ranked =
        options.documentTypeId === ''
            ? available
            : [
                  ...available.filter((entry) =>
                      entry.documentTypeIds.includes(options.documentTypeId),
                  ),
                  ...available.filter(
                      (entry) =>
                          !entry.documentTypeIds.includes(
                              options.documentTypeId,
                          ),
                  ),
              ];

    return narrow(
        ranked.map((entry) => entry.key),
        options.typed,
    );
}

/**
 * The values previously filed under this row's key.
 *
 * Nothing until the key is one the workspace already uses: with no key there is
 * no set of values to have an opinion about, and offering every value in the
 * archive would be noise rather than a hint.
 *
 * The row's key is matched folded, so a document filed as `nif` before the
 * workspace settled on `NIF` still gets its own values back. A true alias
 * (`Nº Contribuinte`) will not match, which is the intended nudge: the
 * suggestions appear once the row is spelled the way the workspace spells it.
 */
export function suggestValues(
    vocabulary: MetadataVocabularyEntry[],
    options: { key: string; typed: string },
): string[] {
    const key = fold(options.key);

    if (key === '') {
        return [];
    }

    const entry = vocabulary.find((candidate) => fold(candidate.key) === key);

    if (entry === undefined) {
        return [];
    }

    return narrow(entry.values, options.typed);
}
