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
     * This column is a denormalised mirror of the extracted text of every one
     * of a document's attachments, concatenated. The source of truth is
     * `document_attachments.ocr_text`; this copy exists solely so the text is
     * searchable.
     *
     * It has to live here because Scout's `database` engine searches columns on
     * the searchable model's own table and cannot traverse a relation, so text
     * held only on the attachments would never be matched by
     * `Document::search()`.
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->longText('ocr_text')->nullable()->after('metadata');
            $table->fullText('ocr_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropFullText(['ocr_text']);
            $table->dropColumn('ocr_text');
        });
    }
};
