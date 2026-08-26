<?php

namespace App\Actions\Organization;

use App\Models\OrganizationScheme;

class UpdateScheme
{
    /**
     * Update an OrganizationScheme's attributes.
     */
    public function handle(OrganizationScheme $scheme, string $name): OrganizationScheme
    {
        $scheme->update([
            'name' => $name,
        ]);

        return $scheme;
    }
}
