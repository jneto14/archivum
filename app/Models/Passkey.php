<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Laravel\Passkeys\Passkey as BasePasskey;

/**
 * Overrides the vendor Passkey model to use UUIDv7 primary keys, matching
 * every other domain model in this app. The vendor model documents `$id` as
 * `int` and never generates one, which doesn't match the `uuid('id')`
 * primary key this app's passkeys migration actually creates — without this
 * override, inserting a passkey fails with no id to write.
 *
 * @property string $id
 */
class Passkey extends BasePasskey
{
    use HasUuids;
}
