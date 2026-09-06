<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            // Its own column rather than clearing `ocr_status`: the status is
            // the record of what happened to the file and stays on the
            // document page, while this only says somebody has seen it and
            // does not need reminding on the review queue (ARC-118).
            //
            // A duplicate dismisses by nulling the flag that put it in the
            // queue, which works there because the flag is nothing else.
            $table->timestamp('ocr_review_dismissed_at')
                ->nullable()
                ->after('duplicate_of_attachment_id');

            // The review queue asks for one workspace's undismissed poor
            // readings on every page load, through the sidebar badge.
            $table->index(['ocr_status', 'ocr_review_dismissed_at'], 'document_attachments_ocr_review_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->dropIndex('document_attachments_ocr_review_index');
            $table->dropColumn('ocr_review_dismissed_at');
        });
    }
};
