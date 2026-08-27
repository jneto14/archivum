<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     *
     * @param Request $request The incoming request.
     *
     * @return string|null The current asset version, or null when versioning isn't configured.
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The shared props merged with the parent's default shared props.
     */
    public function share(Request $request): array
    {
        $user = $request->user();

        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');

        $workspaces = $user
            ? $user->workspaces()->orderBy('workspace_user.created_at')->get()
            : collect();

        $currentMembership = $workspace ? $workspaces->firstWhere('id', $workspace->id) : null;
        $currentRole = $currentMembership ? WorkspaceRole::from((string) $currentMembership->pivot->role) : null;

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'locale' => App::getLocale(),
            'auth' => [
                'user' => $user,
            ],
            'sidebarOpen' => !$request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'workspace' => $workspace ? [
                'id' => $workspace->id,
                'name' => $workspace->name,
            ] : null,
            'workspaces' => $workspaces->map(fn ($w) => [
                'id' => $w->id,
                'name' => $w->name,
                'role' => (string) $w->pivot->role,
            ])->all(),
            'canSwitchWorkspace' => (bool) config('archivum.multi_workspace_enabled'),
            'isWorkspaceAdmin' => $currentRole === WorkspaceRole::Admin,
            'documentsCount' => $workspace ? app(CalculateWorkspaceUsage::class)->documents($workspace) : null,
        ];
    }
}
