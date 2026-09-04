<?php

declare(strict_types=1);

/*
| Vocabulary the intake heuristics read documents with, per language.
|
| This file is the extension point. Adding a language to `archivum.locales` and
| translating this file teaches the reader that language — there is no list of
| countries in the code, and nothing to change to file documents from a new one.
|
| Every configured language is searched at once, not just the interface's:
| an archive holds an English invoice and a Portuguese receipt side by side.
|
| Accents and case do not matter; both the document and these words are folded
| before they are compared.
*/

return [

    /*
    | What to call each of these when showing it to somebody — on the review
    | queue, and in the list of words a workspace has adopted.
    |
    | Only the kinds this file seeds have a name here. Every other kind is a
    | metadata key somebody typed, and is shown as they spelled it: there is no
    | list of kinds in the code to give names to. See IntakeVocabulary.
    */
    'names' => [
        'document_date' => 'Document date',
        'amount' => 'Amount',
        'tax_id' => 'Tax number',
        'vehicle_registration' => 'Vehicle registration',
    ],

    /*
    | Words that introduce a value on the page — "VAT registration 501 234 567".
    | The label is what makes a value recognisable without knowing the country's
    | format for it, so this is where recall comes from. Keep them specific:
    | a word that appears in ordinary prose will match ordinary prose.
    |
    | The keys here are also the kinds a new archive can read before it has
    | learned anything of its own — without them it would read nothing until
    | somebody had filled the same field in by hand several times.
    */
    'labels' => [
        'tax_id' => [
            'VAT',
            'VAT number',
            'VAT registration',
            'VAT reg',
            'VAT no',
            'tax number',
            'tax no',
            'tax id',
            'company number',
        ],
        'vehicle_registration' => [
            'registration',
            'registration number',
            'plate',
            'number plate',
            'licence plate',
            'license plate',
        ],
    ],

    /*
    | Names this language's users are likely to have given a metadata field
    | themselves. A suggestion adopts a key the document's type already uses
    | when it matches one of these, rather than adding a second field meaning
    | the same thing. See SuggestDocumentMetadata.
    */
    'aliases' => [
        'amount' => ['amount', 'total', 'price', 'value', 'sum'],
        'tax_id' => ['tax id', 'tax number', 'vat', 'vat number'],
        'vehicle_registration' => ['vehicle registration', 'registration', 'plate', 'vehicle'],
    ],

];
