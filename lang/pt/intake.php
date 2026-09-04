<?php

declare(strict_types=1);

/*
| Vocabulário com que as heurísticas de entrada leem os documentos, por língua.
|
| Ver lang/en/intake.php para o que cada bloco faz. Todas as línguas
| configuradas são procuradas ao mesmo tempo, não apenas a do interface.
*/

return [

    'labels' => [
        'tax_id' => [
            'NIF',
            'NIPC',
            'contribuinte',
            'número de contribuinte',
            'número fiscal',
            'nº contribuinte',
        ],
        'vehicle_registration' => [
            'matrícula',
        ],
    ],

    'aliases' => [
        'amount' => ['valor', 'total', 'montante', 'preço', 'importância'],
        'tax_id' => ['nif', 'nipc', 'contribuinte'],
        'vehicle_registration' => ['matrícula', 'veículo'],
    ],

];
