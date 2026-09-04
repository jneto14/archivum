<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\IntakeLabelStatus;
use Database\Factories\IntakeLabelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A phrase this workspace's own documents were seen writing in front of a
 * value, offered to an admin as a label the reader could use.
 *
 * @property string $id
 * @property string $workspace_id
 * @property string $kind The normalised metadata key the phrase introduces — see IntakeVocabulary::kindForKey()
 * @property string $label
 * @property IntakeLabelStatus $status
 * @property int $support
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'kind', 'label', 'status', 'support'])]
class IntakeLabel extends Model
{
    /** @use HasFactory<IntakeLabelFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string> Casts the status to its enum.
     */
    protected function casts(): array
    {
        return ['status' => IntakeLabelStatus::class];
    }

    /**
     * @return BelongsTo<Workspace, $this> The workspace whose vocabulary this belongs to.
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @param Builder<IntakeLabel> $query The query to constrain.
     *
     * @return void Limits it to labels the workspace said yes to.
     */
    public function scopeAccepted(Builder $query): void
    {
        $query->where('status', IntakeLabelStatus::Accepted);
    }

    /**
     * @param Builder<IntakeLabel> $query The query to constrain.
     *
     * @return void Limits it to candidates nobody has answered yet.
     */
    public function scopePending(Builder $query): void
    {
        $query->where('status', IntakeLabelStatus::Pending);
    }

    /**
     * Candidates worth putting in front of somebody.
     *
     * A phrase is recorded from the first document that evidences it, because
     * that is how the evidence accumulates — but one document agreeing with
     * itself is not a finding. Any page has some word in front of any value, so
     * until several documents say the same thing there is nothing to ask about.
     *
     * The threshold lives here rather than at the point of writing so that
     * raising `INTAKE_LABEL_MIN_SUPPORT` takes effect on what has already been
     * mined, instead of only on what is filed afterwards.
     *
     * @param Builder<IntakeLabel> $query The query to constrain.
     *
     * @return void Limits it to unanswered candidates enough documents agree on.
     */
    public function scopeOffered(Builder $query): void
    {
        $query->pending()->where('support', '>=', self::minimumSupport());
    }

    /**
     * @return int How many documents must evidence a phrase before it is offered. Floored at two, because a threshold of one offers every word on every page.
     */
    public static function minimumSupport(): int
    {
        return max(2, (int) config('archivum.intake.label_min_support', 3));
    }

    /**
     * @return BelongsToMany<Document, $this> The documents seen writing this phrase in front of a value.
     */
    public function documents(): BelongsToMany
    {
        return $this->belongsToMany(Document::class, 'intake_label_documents');
    }
}
