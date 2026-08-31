<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Models\Document;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Activitylog\Models\Activity;

class DashboardController extends Controller
{
    /**
     * Show the workspace overview: headline counts, the documents touched most
     * recently, and the tail of the activity feed.
     *
     * Renders an onboarding state instead when the user has no workspace yet,
     * so a fresh install doesn't land on an empty grid.
     *
     * @param Request $request The incoming request, carrying the resolved workspace.
     * @param CalculateWorkspaceUsage $usage Computes the workspace's document, user, and storage totals.
     *
     * @return Response The rendered dashboard page.
     */
    public function index(Request $request, CalculateWorkspaceUsage $usage): Response
    {
        /** @var Workspace|null $workspace */
        $workspace = $request->attributes->get('workspace');

        if ($workspace === null) {
            return Inertia::render('dashboard', [
                'workspace' => null,
                'stats' => null,
                'recentDocuments' => [],
                'recentActivity' => [],
            ]);
        }

        return Inertia::render('dashboard', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'stats' => [
                'documents' => $usage->documents($workspace),
                'users' => $usage->users($workspace),
                'attachments' => $usage->attachments($workspace),
                'storage_bytes' => $usage->storageBytes($workspace),
            ],
            'recentDocuments' => $this->recentDocuments($workspace),
            'recentActivity' => $this->recentActivity($workspace),
        ]);
    }

    /**
     * The five most recently updated documents in the workspace.
     *
     * @param Workspace $workspace The workspace to read documents from.
     *
     * @return array<int, array{id: string, title: string, document_type: string|null, updated_at: string|null}>
     */
    private function recentDocuments(Workspace $workspace): array
    {
        return Document::query()
            ->where('workspace_id', $workspace->id)
            ->with('documentType')
            ->latest('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (Document $document) => [
                'id' => $document->id,
                'title' => $document->title,
                'document_type' => $document->documentType?->name,
                'updated_at' => $document->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /**
     * The five most recent activity entries in the workspace.
     *
     * @param Workspace $workspace The workspace to read activity from.
     *
     * @return array<int, array{id: int, label: string|null, event: string|null, created_at: string|null}>
     */
    private function recentActivity(Workspace $workspace): array
    {
        return Activity::query()
            ->where('workspace_id', $workspace->id)
            ->latest('id')
            ->limit(5)
            ->get()
            ->map(function (Activity $activity): array {
                $label = $activity->getProperty('label');

                return [
                    'id' => $activity->id,
                    'label' => is_string($label) ? $label : null,
                    'event' => $activity->event,
                    'created_at' => $activity->created_at?->toIso8601String(),
                ];
            })
            ->all();
    }
}
