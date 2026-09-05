<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Vocabulary a workspace learned from its own documents, on top of the
     * words shipped in `lang/{locale}/intake.php`.
     *
     * Scoped to a workspace rather than the installation, because a phrase
     * learned from one supplier's layout can be meaningless — or actively
     * wrong — in another archive. A word that turns out to be a bad label
     * degrades every reading in the workspace that accepted it, and nowhere
     * else.
     */
    public function up(): void
    {
        Schema::create('intake_labels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('workspace_id')->constrained()->cascadeOnDelete();

            // A metadata key, normalised — the key *is* the kind, because the
            // archive has already named what it files. Not an enum and not a
            // list in the code: nobody knows whether the next installation
            // holds invoices, policies or building permits. See
            // IntakeVocabulary.
            $table->string('kind');

            // Folded the same way the page is before the two are compared, so
            // "Nº Contribuinte" and "no contribuinte" are one row rather than
            // two spellings of one word.
            $table->string('label');

            // The metadata key exactly as somebody typed it, kept so a learned
            // kind can be named without going back to the documents. `kind` is
            // a normalisation and reads as machinery — showing an admin
            // "auto_n" where they wrote "Auto nº" is showing them the inside.
            // Null for the kinds the language files name themselves.
            $table->string('field')->nullable();

            $table->string('status')->index();

            // How many documents were seen writing this phrase in front of a
            // value of that kind. Kept after acceptance: it is what an admin
            // judges a candidate on, and what explains an old one later.
            $table->unsignedInteger('support')->default(0);

            $table->timestamps();

            // One row per phrase per kind, so a later mining run updates the
            // evidence rather than proposing what was already answered.
            $table->unique(['workspace_id', 'kind', 'label']);
            $table->index(['workspace_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('intake_labels');
    }
};
