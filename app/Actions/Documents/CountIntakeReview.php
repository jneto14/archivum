<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Enums\IntakeLabelStatus;
use App\Models\IntakeLabel;
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
     * @param bool $canAnswerLabels Whether the current user may answer learned labels, which only a workspace admin can. Counting them for anybody else would badge a section they are not shown.
     *
     * @return int Documents with suggestions still to review, plus attachments still flagged as duplicates, plus the candidate labels waiting on an admin.
     */
    public function handle(Workspace $workspace, bool $canAnswerLabels = false): int
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
                    ) as duplicates,
                    (
                        select count(*) from intake_labels
                        where workspace_id = ? and status = ? and support >= ?
                    ) as labels
                SQL,
            [
                $workspace->id,
                $workspace->id,
                $workspace->id,
                IntakeLabelStatus::Pending->value,
                // Kept in the same round trip and discarded rather than
                // branched on: a second query shape would be a second thing for
                // `QueryBudgetTest` to hold to account, for a subquery on an
                // indexed column.
                $canAnswerLabels ? IntakeLabel::minimumSupport() : PHP_INT_MAX,
            ],
        );

        return (int) ($counts->suggestions ?? 0)
            + (int) ($counts->duplicates ?? 0)
            + (int) ($counts->labels ?? 0);
    }
}
