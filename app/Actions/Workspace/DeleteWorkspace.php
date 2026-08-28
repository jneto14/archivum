<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\DocumentAttachment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class DeleteWorkspace
{
    /**
     * Delete a Workspace, purging its documents' attachment files from disk
     * before letting the database cascade every dependent row (organization
     * scheme/levels/nodes/rules, documents, tags, memberships, limits).
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

        DocumentAttachment::query()
            ->whereHas('document', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->chunkById(100, function (Collection $attachments): void {
                foreach ($attachments as $attachment) {
                    Storage::disk($attachment->disk)->delete($attachment->path);
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
            throw ValidationException::withMessages([
                'workspace' => __('workspace.cannot_delete_last_workspace'),
            ]);
        }
    }
}
