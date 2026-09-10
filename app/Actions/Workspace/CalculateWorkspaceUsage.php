<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Workspace;
use Closure;
use Illuminate\Database\Eloquent\Builder;

/**
 * Computes a workspace's usage totals, memoising each one for the life of the
 * request.
 *
 * The memo exists because these totals are read more than once per request:
 * `HandleInertiaRequests::share()` reads the document count on *every* page for
 * the sidebar badge, and the dashboard and "Usage & limits" pages then read the
 * same totals again for their own content.
 *
 * Registered as a scoped binding in `AppServiceProvider` so every caller in a
 * request shares one instance — without that the memo would be per-injection
 * and buy nothing.
 *
 * IMPORTANT: any action that creates or deletes a document, an attachment or a
 * workspace member must call `forget()` afterwards. These totals gate the
 * workspace limits (see `CreateDocument`, `UploadAttachment`,
 * `AddWorkspaceUser`), which are checked *before* the write — so a stale memo
 * would both let a workspace exceed its limit on a second write in the same
 * request, and render a sidebar badge that is one behind.
 *
 * The trash is counted by the byte totals and not by the item counts, because
 * the two answer different questions. `storageBytes()` measures a disk, and a
 * trashed document's files are still on it — reporting that space as freed
 * would let a workspace fill a volume behind a number saying it had room. The
 * counts answer "how many documents does this archive hold", and one in the
 * trash is not held: it is absent from every listing, from search and from the
 * sidebar badge, so counting it there would contradict everything the user can
 * see. The document and attachment limits follow the counts for the same
 * reason — a deleted document should not stop somebody filing a new one, while
 * its bytes genuinely do still occupy the disk they are charged against
 * (ARC-123).
 */
class CalculateWorkspaceUsage
{
    /**
     * Memoised totals, keyed by workspace id and then by metric name.
     *
     * @var array<string, array<string, int>>
     */
    private array $totals = [];

    /**
     * Compute all workspace usage totals in one call.
     *
     * @param Workspace $workspace The workspace to compute usage for.
     *
     * @return array{storage_bytes: int, users: int, documents: int, attachments: int, trashed_documents: int, trashed_storage_bytes: int} Current usage totals, trash included, with what the trash accounts for broken out.
     */
    public function handle(Workspace $workspace): array
    {
        return [
            'storage_bytes' => $this->storageBytes($workspace),
            'users' => $this->users($workspace),
            'documents' => $this->documents($workspace),
            'attachments' => $this->attachments($workspace),
            'trashed_documents' => $this->trashedDocuments($workspace),
            'trashed_storage_bytes' => $this->trashedStorageBytes($workspace),
        ];
    }

    /**
     * Sum the byte size of all attachments belonging to the workspace's documents.
     *
     * @param Workspace $workspace The workspace whose attachments are summed.
     *
     * @return int Total attachment size in bytes.
     */
    public function storageBytes(Workspace $workspace): int
    {
        return $this->remember(
            $workspace,
            'storage_bytes',
            fn (): int => (int) $this->attachmentsQuery($workspace, withTrashed: true)->sum('size'),
        );
    }

    /**
     * Count the workspace's members.
     *
     * @param Workspace $workspace The workspace whose members are counted.
     *
     * @return int The number of members.
     */
    public function users(Workspace $workspace): int
    {
        return $this->remember(
            $workspace,
            'users',
            fn (): int => $workspace->users()->count(),
        );
    }

    /**
     * Count the workspace's documents.
     *
     * @param Workspace $workspace The workspace whose documents are counted.
     *
     * @return int The number of documents.
     */
    public function documents(Workspace $workspace): int
    {
        return $this->remember(
            $workspace,
            'documents',
            fn (): int => Document::query()->where('workspace_id', $workspace->id)->count(),
        );
    }

    /**
     * Count attachments across the workspace's documents.
     *
     * @param Workspace $workspace The workspace whose attachments are counted.
     *
     * @return int The number of attachments.
     */
    public function attachments(Workspace $workspace): int
    {
        return $this->remember(
            $workspace,
            'attachments',
            fn (): int => $this->attachmentsQuery($workspace)->count(),
        );
    }

    /**
     * Count the workspace's trashed documents.
     *
     * Reported alongside the totals rather than subtracted from them: the
     * space is genuinely occupied, and the honest way to say so is to show
     * what the trash accounts for next to what everything accounts for. A
     * total that quietly excluded it would be the same lie in the other
     * direction.
     *
     * @param Workspace $workspace The workspace whose trash is counted.
     *
     * @return int The number of documents in the trash.
     */
    public function trashedDocuments(Workspace $workspace): int
    {
        return $this->remember(
            $workspace,
            'trashed_documents',
            fn (): int => Document::onlyTrashed()->where('workspace_id', $workspace->id)->count(),
        );
    }

    /**
     * Sum the bytes held by attachments that are in the trash, whether they
     * were trashed on their own or cascaded with their document.
     *
     * @param Workspace $workspace The workspace whose trash is measured.
     *
     * @return int Bytes recoverable by emptying the trash.
     */
    public function trashedStorageBytes(Workspace $workspace): int
    {
        return $this->remember(
            $workspace,
            'trashed_storage_bytes',
            fn (): int => (int) $this->attachmentsQuery($workspace, withTrashed: true)
                ->onlyTrashed()
                ->sum('size'),
        );
    }

    /**
     * Discard the memoised totals for a workspace.
     *
     * Call this after any write that changes them, so a later read in the same
     * request — a limit check, or the sidebar badge — sees the new values.
     *
     * @param Workspace $workspace The workspace whose totals are now stale.
     *
     * @return void
     */
    public function forget(Workspace $workspace): void
    {
        unset($this->totals[$workspace->id]);
    }

    /**
     * Return the memoised total for a metric, computing it on first use.
     *
     * @param Workspace $workspace The workspace the metric belongs to.
     * @param string $metric The metric's key within the workspace's memo.
     * @param Closure(): int $compute Computes the metric when it isn't memoised yet.
     *
     * @return int The memoised or freshly computed total.
     */
    private function remember(Workspace $workspace, string $metric, Closure $compute): int
    {
        return $this->totals[$workspace->id][$metric] ??= $compute();
    }

    /**
     * @param Workspace $workspace The workspace to scope the attachments query to.
     * @param bool $withTrashed Whether to reach trashed attachments, and attachments hanging off trashed documents.
     *
     * @return Builder<DocumentAttachment> Query builder for attachments belonging to $workspace's documents.
     */
    private function attachmentsQuery(Workspace $workspace, bool $withTrashed = false): Builder
    {
        return DocumentAttachment::query()
            ->when($withTrashed, fn (Builder $query) => $query->withTrashed())
            ->whereHas(
                'document',
                fn (Builder $query) => $query
                    ->when($withTrashed, fn (Builder $documents) => $documents->withTrashed())
                    ->where('workspace_id', $workspace->id),
            );
    }
}
