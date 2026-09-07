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

        expect(
            suggestKeys(vocabulary, { typed: 'matricula', ...noType }),
        ).toEqual(['Matrícula']);
    });

    it('matches a word inside the key, but ranks what starts with it first', () => {
        const vocabulary = [entry('Nº de apólice'), entry('Apólice')];

        expect(
            suggestKeys(vocabulary, { typed: 'apolice', ...noType }),
        ).toEqual(['Apólice', 'Nº de apólice']);
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
});
