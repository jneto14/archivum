<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     *
     * The column arrived meaning "somebody has seen that this scan could not
     * be read". It now means something wider: somebody has looked at what OCR
     * made of this file and said whether it is worth keeping. A confirmed
     * reading and a refused one both set it, and so does acknowledging a page
     * the engine already refused (ARC-118).
     */
    public function up(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->renameColumn('ocr_review_dismissed_at', 'ocr_reviewed_at');
        });

        Schema::table('document_attachments', function (Blueprint $table) {
            $table->dropIndex('document_attachments_ocr_review_index');
            $table->index(['ocr_status', 'ocr_reviewed_at'], 'document_attachments_ocr_review_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->dropIndex('document_attachments_ocr_review_index');
        });

        Schema::table('document_attachments', function (Blueprint $table) {
            $table->renameColumn('ocr_reviewed_at', 'ocr_review_dismissed_at');
        });

        Schema::table('document_attachments', function (Blueprint $table) {
            $table->index(['ocr_status', 'ocr_review_dismissed_at'], 'document_attachments_ocr_review_index');
        });
    }
};
