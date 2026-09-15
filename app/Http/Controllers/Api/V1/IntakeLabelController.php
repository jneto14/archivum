<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Enums\IntakeLabelStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateIntakeLabelRequest;
use App\Http\Resources\Api\V1\IntakeLabelResource;
use App\Jobs\RereadWorkspaceSuggestions;
use App\Models\IntakeLabel;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * The phrases this archive taught itself, and the answer to each.
 */
class IntakeLabelController extends Controller
{
    /**
     * List a workspace's intake labels.
     *
     * Every one of them, unlike the settings screen, which shows only the
     * accepted ones because the unanswered are a queue of work living on the
     * review page. A client has no second screen to send them to, and
     * answering one is the whole reason to read the list.
     *
     * @param Request $request The incoming request, read for an optional `status` filter.
     * @param Workspace $workspace The workspace whose labels are listed.
     *
     * @return AnonymousResourceCollection The workspace's labels, ordered by kind then label.
     *
     * @throws AuthorizationException If the token's user cannot administer $workspace.
     */
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('update', $workspace);

        $status = IntakeLabelStatus::tryFrom((string) $request->query('status'));

        return IntakeLabelResource::collection(
            IntakeLabel::query()
                ->where('workspace_id', $workspace->id)
                ->when($status !== null, fn ($query) => $query->where('status', $status))
                ->orderBy('kind')
                ->orderBy('label')
                ->get(),
        );
    }

    /**
     * Answer a label the archive proposed: accept it, reject it, or retire one
     * already accepted.
     *
     * All three are the same write, which is why there is one route rather
     * than three.
     *
     * @param UpdateIntakeLabelRequest $request The incoming request with the validated status.
     * @param Workspace $workspace The workspace the label belongs to.
     * @param IntakeLabel $intakeLabel The label being answered.
     *
     * @return IntakeLabelResource The answered label.
     *
     * @throws AuthorizationException If the token's user cannot administer $workspace.
     */
    public function update(UpdateIntakeLabelRequest $request, Workspace $workspace, IntakeLabel $intakeLabel): IntakeLabelResource
    {
        $this->authorize('update', $workspace);

        // A label reached through a workspace it does not belong to is not a
        // permission error to explain — from here that row does not exist.
        abort_unless($intakeLabel->workspace_id === $workspace->id, 404);

        $intakeLabel->update([
            'status' => IntakeLabelStatus::from((string) $request->validated('status')),
        ]);

        RereadWorkspaceSuggestions::dispatch($workspace);

        return new IntakeLabelResource($intakeLabel->refresh());
    }
}
