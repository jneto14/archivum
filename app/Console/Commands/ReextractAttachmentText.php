<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Workspace\StartBulkTextExtraction;
use App\Enums\OcrStatus;
use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ReextractionFilter;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

#[Signature('ocr:reextract
    {--workspace= : Restrict to one workspace, by id or name; every workspace when omitted}
    {--document= : Restrict to one document, by id}
    {--status= : Restrict to attachments in this OCR state (failed, completed, poorly_read, skipped, unavailable)}
    {--unscored : Restrict to attachments read before per-word confidence existed, which is how an archive predating 0.4.0 is found}
    {--limit= : Stop after this many attachments per workspace}
    {--dry-run : Report what would be queued and queue nothing}')]
#[Description('Read stored attachments again, so text extracted under an older pipeline catches up with the current one')]
class ReextractAttachmentText extends Command
{
    /**
     * Queue a re-reading of attachments already in the archive.
     *
     * Extraction runs once, at upload, so every change to the pipeline leaves
     * everything filed before it behind — reading differently, scoring
     * differently, and never reaching the review queue. This is how an
     * operator catches an installation up (ARC-122).
     *
     * Deliberately filterable rather than all-or-nothing: on a large archive
     * this is hours of CPU, and the cases worth redoing are usually narrow —
     * the failures, or the attachments from before confidence was recorded.
     * `--dry-run` says how many that is before committing to it.
     *
     * @param StartBulkTextExtraction $start Creates the sweep's task and batches the extractions.
     *
     * @return int The command's exit code.
     */
    public function handle(StartBulkTextExtraction $start): int
    {
        $status = $this->status();

        if ($status === false) {
            return self::FAILURE;
        }

        $workspaces = $this->workspaces();

        if ($workspaces->isEmpty()) {
            $this->error('No matching workspace.');

            return self::FAILURE;
        }

        $filter = new ReextractionFilter(
            documentId: $this->option('document') === null ? null : (string) $this->option('document'),
            status: $status,
            onlyUnscored: (bool) $this->option('unscored'),
        );

        $limit = $this->option('limit') === null ? null : (int) $this->option('limit');
        $queued = 0;

        foreach ($workspaces as $workspace) {
            $queued += $this->sweep($start, $workspace, $filter, $limit);
        }

        $this->info($this->option('dry-run')
            ? "{$queued} attachment(s) would be queued for re-extraction."
            : "Queued {$queued} attachment(s) for re-extraction.");

        return self::SUCCESS;
    }

    /**
     * Queue — or, on a dry run, merely count — one workspace's share.
     *
     * @param StartBulkTextExtraction $start Creates the sweep's task and batches the extractions.
     * @param Workspace $workspace The workspace being swept.
     * @param ReextractionFilter $filter Which of its attachments to cover.
     * @param int|null $limit Stop after this many attachments, or null for all of them.
     *
     * @return int How many attachments were queued, or would be.
     */
    private function sweep(
        StartBulkTextExtraction $start,
        Workspace $workspace,
        ReextractionFilter $filter,
        ?int $limit,
    ): int {
        $matching = $start->count($workspace, $filter, $limit);

        if ($this->option('dry-run')) {
            $this->line("{$workspace->name}: {$matching}");

            return $matching;
        }

        // The action refuses an empty sweep and a workspace already running
        // one. Across every workspace on an installation both are ordinary,
        // so they are reported and stepped over rather than ending the run.
        try {
            $start->handle($workspace, $this->operator($workspace), $filter, $limit);
        } catch (ValidationException $exception) {
            $this->warn("{$workspace->name}: " . $exception->getMessage());

            return 0;
        }

        $this->line("{$workspace->name}: {$matching}");

        return $matching;
    }

    /**
     * The OCR state to restrict to, if one was asked for.
     *
     * @return OcrStatus|false|null The state, null for no restriction, or false if the option was not a state.
     */
    private function status(): OcrStatus|null|false
    {
        $status = $this->option('status');

        if ($status === null) {
            return null;
        }

        $resolved = OcrStatus::tryFrom((string) $status);

        if ($resolved === null) {
            $this->error("Unknown OCR status \"{$status}\".");
        }

        return $resolved ?? false;
    }

    /**
     * The workspaces to sweep.
     *
     * @return Collection<int, Workspace> The matching workspaces.
     */
    private function workspaces(): Collection
    {
        $workspace = $this->option('workspace');

        return Workspace::query()
            ->when($workspace !== null, fn ($query) => $query
                ->where('id', $workspace)
                ->orWhere('name', $workspace))
            ->orderBy('name')
            ->get();
    }

    /**
     * Whose name the sweep is filed under on the Tasks page.
     *
     * A task belongs to the user who triggered it, and nobody triggered this
     * one from inside the application. An admin of the workspace is the
     * closest true answer: they are who would have pressed the button, and who
     * can see the row that results.
     *
     * @param Workspace $workspace The workspace being swept.
     *
     * @return User An admin of $workspace.
     *
     * @throws RuntimeException If the workspace has no admin to attribute the sweep to.
     */
    private function operator(Workspace $workspace): User
    {
        return $workspace->users()
            ->wherePivot('role', WorkspaceRole::Admin)
            ->orderBy('workspace_user.created_at')
            ->first() ?? throw new RuntimeException("Workspace \"{$workspace->name}\" has no admin to attribute the re-extraction to.");
    }
}
