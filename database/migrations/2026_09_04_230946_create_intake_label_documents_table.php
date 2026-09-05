<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Which documents were seen writing a phrase in front of a value, rather
     * than only how many.
     *
     * The count is why this table exists. Labels are learned one document at a
     * time, as somebody saves metadata or as text finishes extracting, so the
     * support figure is arrived at by adding to it — and the same document
     * edited twice would add to it twice. Recording the documents makes
     * re-reading one idempotent by construction, and `support` a derived number
     * that cannot drift from what it claims to count.
     *
     * It also answers a question a number cannot. An admin deciding whether
     * "Steuernummer" is a real label can open the three documents that said so,
     * where "seen on 3 documents" only asks to be believed.
     */
    public function up(): void
    {
        Schema::create('intake_label_documents', function (Blueprint $table) {
            $table->foreignUuid('intake_label_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();

            // One row per pair, which is what makes a document's contribution
            // to a phrase countable once however often it is re-read.
            $table->primary(['intake_label_id', 'document_id']);

            // Re-mining starts from the document, so its rows are looked up by
            // that side as often as by the label's.
            $table->index('document_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_label_documents');
    }
};
