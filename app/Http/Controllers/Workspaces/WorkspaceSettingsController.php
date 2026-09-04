<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Enums\IntakeLabelStatus;
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

        // Pending first and by weight of evidence, because that is the order an
        // admin wants to answer them in. Rejected ones are not sent: they are
        // recorded so mining stops asking, not so anybody re-reads them.
        $intakeLabels = IntakeLabel::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('status', [IntakeLabelStatus::Pending, IntakeLabelStatus::Accepted])
            ->orderByDesc('support')
            ->orderBy('label')
            ->get(['id', 'kind', 'label', 'status', 'support'])
            ->groupBy(fn (IntakeLabel $label): string => $label->status->value);

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
            'intakeLabels' => [
                'pending' => $this->presentLabels($intakeLabels->get(IntakeLabelStatus::Pending->value)),
                'accepted' => $this->presentLabels($intakeLabels->get(IntakeLabelStatus::Accepted->value)),
            ],
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
     * @param Collection<int, IntakeLabel>|null $labels The rows to present, or null where the group is empty.
     *
     * @return list<array{id: string, kind: string, label: string, support: int}> One entry per label.
     */
    private function presentLabels(?Collection $labels): array
    {
        $presented = [];

        foreach ($labels ?? [] as $label) {
            $presented[] = [
                'id' => $label->id,
                'kind' => $label->kind,
                'label' => $label->label,
                'support' => $label->support,
            ];
        }

        return $presented;
    }
}
