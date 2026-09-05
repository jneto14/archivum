<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Documents\LearnIntakeLabels as LearnLabels;
use App\Models\Workspace;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('archivum:learn-intake-labels {--workspace= : Mine one workspace by id, rather than every one}')]
#[Description('Mine documents filed before the archive started learning, and offer each workspace the words they suggest')]
class LearnIntakeLabels extends Command
{
    /**
     * Mine every workspace, or one, for candidate labels.
     *
     * The backfill, and nothing else. Learning happens a document at a time as
     * documents are saved and extracted (see `LearnDocumentIntakeLabels`), so
     * this is not scheduled and is not meant to be: it exists for the archive
     * that was already there when this feature arrived, which no edit is going
     * to touch and which is where most of the evidence lives.
     *
     * Nothing it writes is used until an admin accepts it, so running it again
     * is safe at any time — a document already mined contributes exactly what
     * it contributed before.
     *
     * @param LearnLabels $learn Reads the documents and records what they suggest.
     *
     * @return int The command's exit code.
     */
    public function handle(LearnLabels $learn): int
    {
        $workspaceId = $this->option('workspace');

        Workspace::query()
            ->when(
                is_string($workspaceId) && $workspaceId !== '',
                fn ($query) => $query->whereKey($workspaceId),
            )
            ->chunkById(50, function (Collection $workspaces) use ($learn): void {
                foreach ($workspaces as $workspace) {
                    $waiting = $learn->handle($workspace);

                    $this->line("{$workspace->name}: {$waiting} candidate label(s) awaiting review.");
                }
            });

        return self::SUCCESS;
    }
}
