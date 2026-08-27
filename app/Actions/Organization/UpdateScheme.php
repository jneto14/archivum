<?php

namespace App\Actions\Organization;

use App\Models\OrganizationScheme;

class UpdateScheme
{
    /**
     * Update an OrganizationScheme's attributes.
     *
     * @param  OrganizationScheme  $scheme  The scheme to update.
     * @param  string  $name  The scheme's new name.
     * @return OrganizationScheme The updated scheme.
     */
    public function handle(OrganizationScheme $scheme, string $name): OrganizationScheme
    {
        $scheme->update([
            'name' => $name,
        ]);

        return $scheme;
    }
}
