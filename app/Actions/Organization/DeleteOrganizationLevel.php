<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Models\OrganizationLevel;
use App\Support\Refusal;
use Illuminate\Validation\ValidationException;

class DeleteOrganizationLevel
{
    /**
     * Delete a single organization level.
     *
     * Only the last level (highest position) in its scheme can be removed,
     * and only when it has no nodes yet — mirroring DeleteOrganizationNode's
     * guard against destroying populated structure.
     *
     * @param OrganizationLevel $level The level to delete.
     *
     * @return void No return value on success.
     *
     * @throws ValidationException If $level is not the last level in its scheme, or has nodes.
     */
    public function handle(OrganizationLevel $level): void
    {
        $this->assertIsLastLevel($level);
        $this->assertHasNoNodes($level);

        $level->delete();
    }

    /**
     * @param OrganizationLevel $level The level to check.
     *
     * @return void No return value when $level is the last level in its scheme.
     *
     * @throws ValidationException If another level in the scheme has a higher position.
     */
    private function assertIsLastLevel(OrganizationLevel $level): void
    {
        $maxPosition = (int) $level->scheme->levels()->max('position');

        if ($level->position !== $maxPosition) {
            throw Refusal::because(__('organization.level_not_last'));
        }
    }

    /**
     * @param OrganizationLevel $level The level to check.
     *
     * @return void No return value when $level has no nodes.
     *
     * @throws ValidationException If $level has any nodes.
     */
    private function assertHasNoNodes(OrganizationLevel $level): void
    {
        if ($level->nodes()->exists()) {
            throw Refusal::because(__('organization.level_has_nodes'));
        }
    }
}
