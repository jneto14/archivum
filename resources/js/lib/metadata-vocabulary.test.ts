import { describe, expect, it } from 'vitest';
import type { MetadataVocabularyEntry } from './metadata-vocabulary';
import { suggestKeys, suggestValues } from './metadata-vocabulary';

const entry = (
    key: string,
    values: string[] = [],
    documentTypeIds: string[] = [],
): MetadataVocabularyEntry => ({ key, values, documentTypeIds });

const noType = { documentTypeId: '', usedKeys: [] };

describe('suggestKeys', () => {
    it('offers the whole vocabulary, in the order it arrives, before anything is typed', () => {
        const vocabulary = [entry('Fornecedor'), entry('Apólice')];

        expect(suggestKeys(vocabulary, { typed: '', ...noType })).toEqual([
            'Fornecedor',
            'Apólice',
        ]);
    });

    it('matches without regard to case or accents', () => {
        const vocabulary = [entry('Matrícula')];

        expect(suggestKeys(vocabulary, { typed: 'matric', ...noType })).toEqual(
            ['Matrícula'],
        );
    });

    it('matches a word inside the key, but ranks what starts with it first', () => {
        const vocabulary = [entry('Nº de apólice'), entry('Apólice')];

        expect(suggestKeys(vocabulary, { typed: 'apol', ...noType })).toEqual([
            'Apólice',
            'Nº de apólice',
        ]);
    });

    it('never offers back what is already in the field', () => {
        const vocabulary = [entry('Fornecedor'), entry('Fornecedor externo')];

        // Otherwise every already-filled row on an edited document opens a list
        // under itself offering its own key, which is where the suggestions are
        // useless — and the empty row being added is where they are missing.
        expect(
            suggestKeys(vocabulary, { typed: 'Fornecedor', ...noType }),
        ).toEqual(['Fornecedor externo']);
    });

    it('leaves out keys the document is already filing by', () => {
        const vocabulary = [entry('Fornecedor'), entry('Apólice')];

        expect(
            suggestKeys(vocabulary, {
                typed: '',
                documentTypeId: '',
                // Folded, so re-typing a key in a second row with different
                // casing is caught too.
                usedKeys: ['fornecedor'],
            }),
        ).toEqual(['Apólice']);
    });

    it('ranks the selected type first without hiding the rest', () => {
        const vocabulary = [
            entry('Fornecedor', [], ['invoice']),
            entry('Contraparte', [], ['contract']),
        ];

        expect(
            suggestKeys(vocabulary, {
                typed: '',
                documentTypeId: 'contract',
                usedKeys: [],
            }),
        ).toEqual(['Contraparte', 'Fornecedor']);
    });
});

describe('suggestValues', () => {
    it('offers nothing until the row has a key', () => {
        const vocabulary = [entry('Fornecedor', ['EDP'])];

        expect(suggestValues(vocabulary, { key: '', typed: '' })).toEqual([]);
    });

    it('offers nothing for a key the workspace has never filed', () => {
        const vocabulary = [entry('Fornecedor', ['EDP'])];

        expect(suggestValues(vocabulary, { key: 'Novo', typed: '' })).toEqual(
            [],
        );
    });

    it('offers what was filed under the key, matched folded', () => {
        const vocabulary = [entry('Fornecedor', ['EDP', 'Galp'])];

        expect(
            suggestValues(vocabulary, { key: 'fornecedor', typed: '' }),
        ).toEqual(['EDP', 'Galp']);
    });

    it('narrows to what has been typed', () => {
        const vocabulary = [entry('Fornecedor', ['EDP', 'Galp'])];

        expect(
            suggestValues(vocabulary, { key: 'Fornecedor', typed: 'ga' }),
        ).toEqual(['Galp']);
    });

    it('never offers back the value already in the field', () => {
        const vocabulary = [entry('Fornecedor', ['EDP', 'Galp'])];

        expect(
            suggestValues(vocabulary, { key: 'Fornecedor', typed: 'Galp' }),
        ).toEqual([]);
    });
});

/**
 * The shape that was broken on a real archive: a workspace knowing three keys,
 * on a document already filing by two of them.
 */
describe('a document already filing by most of what the workspace knows', () => {
    const vocabulary = [
        entry('teste'),
        entry('amount', ['315.00']),
        entry('tax_id', ['501 234 567']),
    ];
    const rows = [
        { key: 'amount', value: '315.00' },
        { key: 'tax_id', value: '501 234 567' },
    ];
    const others = (index: number) =>
        rows.filter((_, other) => other !== index).map((row) => row.key);

    it('says nothing on the rows that are already filled in', () => {
        rows.forEach((row, index) => {
            expect(
                suggestKeys(vocabulary, {
                    typed: row.key,
                    documentTypeId: '',
                    usedKeys: others(index),
                }),
            ).toEqual([]);
            expect(
                suggestValues(vocabulary, { key: row.key, typed: row.value }),
            ).toEqual([]);
        });
    });

    it('offers the key the document is not using yet on the row being added', () => {
        expect(
            suggestKeys(vocabulary, {
                typed: '',
                documentTypeId: '',
                usedKeys: rows.map((row) => row.key),
            }),
        ).toEqual(['teste']);
    });
});

describe('a value stored as null', () => {
    // A real archive held `teste => NULL`, and opening that document for
    // editing threw "Cannot read properties of null (reading 'normalize')"
    // and rendered nothing at all — not the row, the page. Metadata values
    // were never validated as strings, so the declared `Record<string,
    // string>` was a claim the server had no way of keeping (ARC-126).
    it('does not throw when it reaches the key suggestions', () => {
        expect(() =>
            suggestKeys([entry('nif')], {
                ...noType,
                typed: null as unknown as string,
            }),
        ).not.toThrow();
    });

    it('does not throw when it reaches the value suggestions', () => {
        expect(() =>
            suggestValues([entry('nif', ['123'])], {
                key: 'nif',
                typed: null as unknown as string,
            }),
        ).not.toThrow();
    });

    it('is treated as an empty query rather than as a filter', () => {
        expect(
            suggestValues([entry('nif', ['123', '456'])], {
                key: 'nif',
                typed: null as unknown as string,
            }),
        ).toEqual(['123', '456']);
    });

    it('does not throw when a vocabulary key itself is null', () => {
        expect(() =>
            suggestKeys([entry(null as unknown as string)], {
                ...noType,
                typed: 'nif',
            }),
        ).not.toThrow();
    });
});
