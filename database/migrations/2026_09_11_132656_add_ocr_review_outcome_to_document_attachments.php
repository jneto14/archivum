<?php

declare(strict_types=1);

use App\Enums\OcrReviewOutcome;
use App\Enums\OcrStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     *
     * `ocr_reviewed_at` records that the question was answered. Until now that
     * was the whole answer, because every way of setting it went through one
     * person looking at one page. Bulk review (ARC-127) adds a way that does
     * not, so the outcome has to be stored next to the timestamp — otherwise
     * clearing a thousand readings is indistinguishable from a thousand people
     * having read them, which is the claim ARC-118 exists to protect.
     *
     * Backfilled, not left null: every row answered before this migration was
     * answered by somebody looking, so saying nothing about them would lose
     * exactly the fact the column is being added to keep.
     *
     * A poorly-read row is `Acknowledged` rather than `Rejected` because the
     * two are indistinguishable in the old data — refusing a reading also
     * leaves the status poorly read — and neither of them vouches for text,
     * which is the distinction being preserved. Everything else is
     * `Confirmed`: it has text, and somebody kept it.
     */
    public function up(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->string('ocr_review_outcome')->nullable()->after('ocr_reviewed_at');
        });

        DB::table('document_attachments')
            ->whereNotNull('ocr_reviewed_at')
            ->where('ocr_status', OcrStatus::PoorlyRead->value)
            ->update(['ocr_review_outcome' => OcrReviewOutcome::Acknowledged->value]);

        DB::table('document_attachments')
            ->whereNotNull('ocr_reviewed_at')
            ->whereNull('ocr_review_outcome')
            ->update(['ocr_review_outcome' => OcrReviewOutcome::Confirmed->value]);
    }

    /**
     * Reverse the migrations.
     *
     * The timestamp survives, so going back loses which of the four answers it
     * was and keeps the fact that there was one.
     */
    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->dropColumn('ocr_review_outcome');
        });
    }
};
