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
    | Words that introduce a value on the page — "VAT registration 501 234 567".
    | The label is what makes a value recognisable without knowing the country's
    | format for it, so this is where recall comes from. Keep them specific:
    | a word that appears in ordinary prose will match ordinary prose.
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
