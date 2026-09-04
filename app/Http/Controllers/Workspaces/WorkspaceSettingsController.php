<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Actions\Documents\IntakeVocabulary;
use App\Http\Controllers\Controller;
use App\Models\IntakeLabel;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class WorkspaceSettingsController extends Controller
{
    /**
     * Show a workspace's settings: its name, organization scheme, read-only
     * instance flags, the current user's API tokens, account links, and —
     * for platform admins only — its configured resource limits.
     *
     * @param Request $request The incoming request, used to resolve the acting user.
     * @param Workspace $workspace The workspace whose settings are being viewed.
     *
     * @return Response The rendered workspace settings page.
     *
     * @throws AuthorizationException If the current user cannot update $workspace.
     */
    public function show(Request $request, Workspace $workspace): Response
    {
        $this->authorize('update', $workspace);

        $scheme = OrganizationScheme::query()->where('workspace_id', $workspace->id)->first(['id', 'name']);
        $isPlatformAdmin = (bool) $request->user()->is_platform_admin;

        // Only the ones in use. Unanswered candidates are a queue of work, and
        // they live on the review page with everything else the application
        // worked out and cannot confirm on its own; what belongs here is the
        // standing list, and the way to retire something off it. Rejected ones
        // are not sent either: they are recorded so mining stops asking, not so
        // anybody re-reads them.
        $intakeLabels = IntakeLabel::query()
            ->where('workspace_id', $workspace->id)
            ->accepted()
            ->orderBy('kind')
            ->orderBy('label')
            ->get(['id', 'kind', 'label', 'support']);

        return Inertia::render('workspace/settings', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'scheme' => $scheme !== null ? ['id' => $scheme->id, 'name' => $scheme->name] : null,
            'instance' => [
                'multi_workspace_enabled' => (bool) config('archivum.multi_workspace_enabled'),
                'attachments_disk' => config('archivum.attachments.disk'),
            ],
            'tokens' => $request->user()->tokens->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'created_at_diff' => $token->created_at?->diffForHumans(),
                'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
            ])->values()->all(),
            'intakeLabels' => $this->presentLabels($intakeLabels, $workspace),
            'isPlatformAdmin' => $isPlatformAdmin,
            'limits' => $isPlatformAdmin ? [
                'storage_bytes' => $workspace->limits?->storage_bytes,
                'users' => $workspace->limits?->users,
                'documents' => $workspace->limits?->documents,
                'attachments' => $workspace->limits?->attachments,
            ] : null,
        ]);
    }

    /**
     * @param Collection<int, IntakeLabel> $labels The rows to present.
     * @param Workspace $workspace The workspace they belong to, whose spelling of each field is shown.
     *
     * @return list<array{id: string, kind: string, field: string, label: string, support: int}> One entry per label.
     */
    private function presentLabels(Collection $labels, Workspace $workspace): array
    {
        $vocabulary = app(IntakeVocabulary::class);
        $presented = [];

        foreach ($labels as $label) {
            $presented[] = [
                'id' => $label->id,
                'kind' => $label->kind,
                // A shipped kind has a name in the interface language; one the
                // archive invented is shown as this workspace spells it.
                'field' => $vocabulary->nameFor($label->kind, $workspace->id),
                'label' => $label->label,
                'support' => $label->support,
            ];
        }

        return $presented;
    }
}
