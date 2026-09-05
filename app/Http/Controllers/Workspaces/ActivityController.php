<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use App\Support\TableSort;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class ActivityController extends Controller
{
    /**
     * List the workspace's activity feed, most recent first by default.
     *
     * @param Request $request The incoming request, read for the chosen order.
     * @param Workspace $workspace The workspace whose activity is listed.
     *
     * @return Response The rendered "Atividade" Inertia page.
     *
     * @throws AuthorizationException If the current user cannot view $workspace's activity feed.
     */
    public function index(Request $request, Workspace $workspace): Response
    {
        $this->authorize('viewAny', [Activity::class, $workspace]);

        $sort = TableSort::fromRequest($request, [
            'log_name' => 'activity_log.log_name',
            'event' => 'activity_log.event',
            'causer' => DB::raw('(select name from users where users.id = activity_log.causer_id)'),
            'created_at' => 'activity_log.created_at',
        ], 'created_at', 'desc');

        $activities = Activity::query()
            ->where('workspace_id', $workspace->id)
            ->with('causer')
            ->tap(fn (Builder $query) => $sort->apply($query, 'activity_log.id'))
            ->paginate(25)
            ->onEachSide(1)
            // Without this the chosen order is dropped the moment somebody
            // turns the page, and the feed silently reverts to its default.
            ->withQueryString();

        return Inertia::render('workspace/activity', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'sort' => $sort->toArray(),
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
