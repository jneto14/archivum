<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\ActivityResource;
use App\Models\Workspace;
use App\Support\PageSize;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Spatie\Activitylog\Models\Activity;

class ActivityController extends Controller
{
    /**
     * Read a workspace's audit trail, most recent first.
     *
     * Filterable by `event` and `log_name`, which is what turns a feed into an
     * answer to a question — everything deleted last week, everything one
     * person did.
     *
     * @param Request $request The incoming request, read for filters and the page size.
     * @param Workspace $workspace The workspace whose trail is read.
     *
     * @return AnonymousResourceCollection A page of activity entries.
     *
     * @throws AuthorizationException If the token's user cannot read $workspace's trail.
     */
    public function index(Request $request, Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Activity::class, $workspace]);

        $event = $request->query('event');
        $logName = $request->query('log_name');

        $activities = Activity::query()
            ->where('workspace_id', $workspace->id)
            ->when(is_string($event), fn (Builder $query) => $query->where('event', $event))
            ->when(is_string($logName), fn (Builder $query) => $query->where('log_name', $logName))
            ->with('causer')
            // The id settles the ties. One action writes several entries at
            // the same instant, and an order that leaves them tied lets a
            // client paging the trail see an entry twice and miss the one it
            // displaced.
            ->latest('created_at')
            ->orderByDesc('id')
            ->paginate(PageSize::fromRequest($request))
            ->withQueryString();

        return ActivityResource::collection($activities);
    }
}
