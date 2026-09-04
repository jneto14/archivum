<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Enums\IntakeLabelStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateIntakeLabelRequest;
use App\Models\IntakeLabel;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;

class IntakeLabelController extends Controller
{
    /**
     * Answer a learned label: adopt it, or turn it down.
     *
     * The only write there is on this vocabulary, and it does three jobs. A
     * pending candidate is accepted or rejected; an accepted label is retired by
     * rejecting it, which stops the reader using it *and* stops the next mining
     * run offering it back. Deleting the row would do the first and undo the
     * second, so nothing here deletes.
     *
     * @param UpdateIntakeLabelRequest $request The validated decision.
     * @param Workspace $workspace The workspace whose vocabulary this is.
     * @param IntakeLabel $intakeLabel The label being answered.
     *
     * @return RedirectResponse Back to the settings page the decision was made on.
     *
     * @throws AuthorizationException If the current user cannot update $workspace.
     */
    public function update(
        UpdateIntakeLabelRequest $request,
        Workspace $workspace,
        IntakeLabel $intakeLabel,
    ): RedirectResponse {
        $this->authorize('update', $workspace);

        // A label reached through a workspace it does not belong to is not a
        // permission error to explain — from here that row does not exist.
        abort_unless($intakeLabel->workspace_id === $workspace->id, 404);

        $intakeLabel->update([
            'status' => IntakeLabelStatus::from((string) $request->validated('status')),
        ]);

        return back();
    }
}
