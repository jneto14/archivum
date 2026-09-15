<?php

declare(strict_types=1);

return [
    /*
    | Deliberately says nothing about what was looked for. Laravel's own
    | message names the Eloquent model — "No query results for model
    | [App\Models\Document]" — which tells a client the shape of the code
    | behind the route and tells them nothing they can act on. It is also the
    | answer given when a row exists and is somebody else's, where naming it
    | would confirm the row exists.
    */
    'not_found' => 'Not found.',
];
