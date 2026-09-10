<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Workspace\EmptyWorkspaceTrash;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

/**
 * Destroys documents and attachments that have been in the trash longer than
 * the retention window, which is the point at which their files leave the
 * disk.
 *
 * Without this the trash only ever grows, and since a trashed item still
 * counts against the workspace's storage limit, an archive nobody empties by
 * hand would eventually be unable to file anything new while appearing to
 * hold nothing but deleted paperwork.
 *
 * A retention of 0 disables the sweep entirely: the trash then holds until
 * somebody empties it themselves, which is a legitimate choice for an
 * installation that would rather never lose a record on a timer.
 */
#[Signature('trash:prune')]
#[Description('Permanently delete documents and attachments past the trash retention window')]
class PruneTrashedDocuments extends Command
{
    /**
     * @param EmptyWorkspaceTrash $emptyTrash Purges a workspace's trash, optionally only what predates a cutoff.
     *
     * @return int The command's exit code.
     */
    public function handle(EmptyWorkspaceTrash $emptyTrash): int
    {
        $retentionDays = (int) config('archivum.trash.retention_days');

        if ($retentionDays <= 0) {
            $this->info('Trash retention is disabled; nothing pruned.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays($retentionDays);
        $documents = 0;
        $attachments = 0;

        // Per workspace rather than one sweep over every trashed row, because
        // the purge has to forget each workspace's memoised usage totals and
        // those are keyed by workspace.
        Workspace::query()->chunkById(50, function (Collection $workspaces) use ($emptyTrash, $cutoff, &$documents, &$attachments): void {
            foreach ($workspaces as $workspace) {
                $purged = $emptyTrash->handle($workspace, $cutoff);
                $documents += $purged['documents'];
                $attachments += $purged['attachments'];
            }
        });

        $this->info("Pruned {$documents} document(s) and {$attachments} attachment(s) from the trash.");

        return self::SUCCESS;
    }
}
