<?php

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\MigrateSchemeDocumentsRequest;
use App\Jobs\BulkMoveDocuments;
use App\Models\OrganizationScheme;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class OrganizationSchemeMigrationController extends Controller
{
    /**
     * Dispatch a background job to move all of a scheme's documents onto
     * another scheme within the same workspace.
     *
     * @param  MigrateSchemeDocumentsRequest  $request  The incoming request with the validated target scheme id.
     * @param  OrganizationScheme  $scheme  The source scheme whose documents are migrated away.
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $scheme.
     * @throws ModelNotFoundException If the requested target scheme does not exist in the same workspace as $scheme.
     * @throws ValidationException If the target scheme is the same as $scheme.
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
     *
     * @param  OrganizationScheme  $scheme  The source scheme being migrated away from.
     * @param  string  $targetSchemeId  The UUID of the scheme documents should be migrated onto.
     * @return OrganizationScheme The resolved target scheme.
     *
     * @throws ModelNotFoundException If no scheme with $targetSchemeId exists in the same workspace as $scheme.
     * @throws ValidationException If the resolved target scheme is the same as $scheme.
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
