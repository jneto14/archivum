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
#[Description('Mine filed documents for words the reader could be recognising values by, and offer them to each workspace for approval')]
class LearnIntakeLabels extends Command
{
    /**
     * Mine every workspace, or one, for candidate labels.
     *
     * A batch job rather than something a request triggers: it reads every
     * document a workspace has, and the answer changes slowly — a phrase that
     * is not evidenced by several documents today is not evidenced by a page
     * refresh either.
     *
     * Nothing it writes is used until an admin accepts it, which is why it is
     * safe to leave running on a schedule.
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
