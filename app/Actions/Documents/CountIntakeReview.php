<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Counts what is waiting on the intake review queue.
 *
 * Shared with every page so the sidebar can carry a badge, which is the only
 * thing that makes the queue worth having — a review page nobody knows has
 * anything in it does not get opened. That makes this a query on every request,
 * so it is deliberately one round trip for both halves rather than two, and
 * `QueryBudgetTest` holds the whole application's per-page count to account.
 */
class CountIntakeReview
{
    /**
     * @param Workspace $workspace The workspace to count within.
     *
     * @return int Documents with suggestions still to review, plus attachments still flagged as duplicates.
     */
    public function handle(Workspace $workspace): int
    {
        $counts = DB::selectOne(
            <<<'SQL'
                select
                    (
                        select count(*) from documents
                        where workspace_id = ? and json_length(metadata_suggestions) > 0
                    ) as suggestions,
                    (
                        select count(*) from document_attachments
                        inner join documents on documents.id = document_attachments.document_id
                        where documents.workspace_id = ? and document_attachments.duplicate_of_attachment_id is not null
                    ) as duplicates
                SQL,
            [$workspace->id, $workspace->id],
        );

        return (int) ($counts->suggestions ?? 0) + (int) ($counts->duplicates ?? 0);
    }
}
