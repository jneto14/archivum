<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Actions\Documents\UnlinkAttachmentFiles;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class DeleteWorkspace
{
    public function __construct(private readonly UnlinkAttachmentFiles $unlinkFiles) {}

    /**
     * Delete a Workspace, purging its documents' attachment files from disk
     * before letting the database cascade every dependent row (organization
     * scheme/levels/nodes/rules, documents, tags, memberships, limits).
     *
     * A workspace does not soft-delete: it goes, and the cascade takes the
     * documents, attachments and versions with it whether they were in the
     * trash or not. Nothing in the database knows about the disk, so every
     * file has to be unlinked here, and the trash is not an exception — the
     * rows are about to stop existing, so a file left behind is one no row
     * will ever point at again.
     *
     * Hence `withTrashed()` on both sides. Attachments and documents both
     * soft-delete, so the default scopes would skip trashed attachments and
     * attachments hanging off a trashed document. Expressed as a subquery
     * rather than `whereHas`, because inside a `whereHas` closure the builder
     * is typed against the base model and `withTrashed()` is not on it — the
     * same reason `CalculateWorkspaceUsage` counts those bytes this way.
     *
     * @param Workspace $workspace The workspace to delete.
     *
     * @return void No return value; the workspace and all its data are deleted as a side effect.
     *
     * @throws ValidationException If $workspace is the only workspace in the instance.
     */
    public function handle(Workspace $workspace): void
    {
        $this->assertNotLastWorkspace();

        DocumentAttachment::withTrashed()
            ->whereIn(
                'document_id',
                Document::withTrashed()->where('workspace_id', $workspace->id)->select('id'),
            )
            ->with('versions')
            ->chunkById(100, function (Collection $attachments): void {
                foreach ($attachments as $attachment) {
                    $this->unlinkFiles->handle($attachment);
                }
            });

        $workspace->delete();
    }

    /**
     * @return void No return value when at least one other workspace exists.
     *
     * @throws ValidationException If this is the only workspace in the instance.
     */
    private function assertNotLastWorkspace(): void
    {
        if (Workspace::query()->count() === 1) {
            // Flashed as well as thrown: the message is addressed to a
            // field — 'workspace' — that no page renders, so on its own it
            // arrives and is dropped. The toast is what is actually seen.
            Inertia::flash('toast', ['type' => 'error', 'message' => __('workspace.cannot_delete_last_workspace')]);

            throw ValidationException::withMessages([
                'workspace' => __('workspace.cannot_delete_last_workspace'),
            ]);
        }
    }
}
