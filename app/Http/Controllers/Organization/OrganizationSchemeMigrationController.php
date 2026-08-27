<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\MigrateSchemeDocumentsRequest;
use App\Jobs\BulkMoveDocuments;
use App\Models\OrganizationScheme;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class OrganizationSchemeMigrationController extends Controller
{
    /**
     * Dispatch a background job to move all of a scheme's documents onto
     * another scheme within the same workspace.
     */
    public function store(MigrateSchemeDocumentsRequest $request, OrganizationScheme $scheme): RedirectResponse
    {
        $this->authorize('update', $scheme);

        $target = $this->resolveTargetScheme($scheme, $request->validated('target_scheme_id'));

        BulkMoveDocuments::dispatch($scheme, $target);

        return back();
    }

    /**
     * Resolve the target scheme, ensuring it belongs to the same workspace
     * and differs from the source scheme being migrated.
     */
    private function resolveTargetScheme(OrganizationScheme $scheme, string $targetSchemeId): OrganizationScheme
    {
        $target = OrganizationScheme::query()
            ->where('workspace_id', $scheme->workspace_id)
            ->where('id', $targetSchemeId)
            ->firstOrFail();

        if ($target->id === $scheme->id) {
            throw ValidationException::withMessages([
                'target_scheme_id' => __('organization.migration_target_must_differ'),
            ]);
        }

        return $target;
    }
}
