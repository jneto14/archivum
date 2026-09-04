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
        Schema::table('documents', function (Blueprint $table) {
            // What the extracted text was found to contain, kind and value
            // only. Stored rather than derived on demand so that "which
            // documents have something waiting to be reviewed" is a query
            // instead of running the heuristics over every document in the
            // workspace on every page load.
            //
            // Which field each value belongs in, and whether that field is
            // still empty, is deliberately *not* stored: both change after
            // extraction runs, so they are resolved when the suggestions are
            // read. Emptied once somebody has dealt with the document.
            $table->json('metadata_suggestions')->nullable()->after('ocr_text');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('metadata_suggestions');
        });
    }
};
