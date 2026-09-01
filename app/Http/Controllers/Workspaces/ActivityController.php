<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ActivityController extends Controller
{
    /**
     * List the workspace's activity feed, most recent first.
     *
     * @param Workspace $workspace The workspace whose activity is listed.
     *
     * @return Response The rendered "Atividade" Inertia page.
     *
     * @throws AuthorizationException If the current user cannot view $workspace's activity feed.
     */
    public function index(Workspace $workspace): Response
    {
        $this->authorize('viewAny', [Activity::class, $workspace]);

        $activities = Activity::query()
            ->where('workspace_id', $workspace->id)
            ->with('causer')
            ->latest('id')
            ->paginate(25)
            ->onEachSide(1);

        return Inertia::render('workspace/activity', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'activities' => $activities->through($this->transformActivity(...)),
        ]);
    }

    /**
     * @param Activity $activity The activity to transform into a plain array for the Inertia page.
     *
     * @return array{id: int, log_name: string|null, event: string|null, label: string|null, causer: string|null, created_at: string|null}
     */
    private function transformActivity(Activity $activity): array
    {
        $causer = $activity->causer;
        $label = $activity->getProperty('label');

        return [
            'id' => $activity->id,
            'log_name' => $activity->log_name,
            'event' => $activity->event,
            'label' => is_string($label) ? $label : null,
            'causer' => $causer instanceof User ? $causer->name : null,
            'created_at' => $activity->created_at?->toIso8601String(),
        ];
    }
}
