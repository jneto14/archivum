<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Models\OrganizationLevel;

class UpdateOrganizationLevel
{
    /**
     * Update a level in place.
     *
     * Only whether its nodes carry printable labels can be changed: a level's
     * name, key, capacity and value strategy are read by nodes that already
     * exist, and changing them under those nodes is a migration, not an edit.
     * Whether a label can be printed is read at print time and at nothing else,
     * so it can be turned on and off freely.
     *
     * @param OrganizationLevel $level The level being updated.
     * @param bool $hasPrintableLabel Whether this level's nodes can be given a printed label.
     *
     * @return OrganizationLevel The updated level.
     */
    public function handle(OrganizationLevel $level, bool $hasPrintableLabel): OrganizationLevel
    {
        $level->update(['has_printable_label' => $hasPrintableLabel]);

        return $level;
    }
}
