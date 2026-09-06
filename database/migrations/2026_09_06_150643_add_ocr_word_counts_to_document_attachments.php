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
     * How much of each page the engine could actually read, kept so the review
     * queue can ask about the readings that went badly and leave the clean
     * ones alone. Without it "read badly" has no meaning after extraction
     * finishes: the text alone cannot say what was dropped out of it (ARC-118).
     */
    public function up(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->unsignedInteger('ocr_word_count')->nullable()->after('ocr_text');
            $table->unsignedInteger('ocr_confident_word_count')->nullable()->after('ocr_word_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->dropColumn(['ocr_word_count', 'ocr_confident_word_count']);
        });
    }
};
