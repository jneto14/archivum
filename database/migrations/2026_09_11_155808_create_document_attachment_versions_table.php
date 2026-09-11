<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class() extends Migration
{
    /**
     * Run the migrations.
     *
     * An attachment stops being one file and becomes a chain: the file that is
     * current stays on `document_attachments`, and every file it superseded
     * moves here. Keeping the current one where it was is what makes this
     * change small — every existing query about an attachment's file, from the
     * download endpoint to the storage total, still reads one row (ARC-124).
     *
     * The file columns are deliberately a copy of the attachment's own rather
     * than a shared table both point at. They describe a file; the attachment
     * row describes the file it is holding right now.
     *
     * Nothing derived from text comes with them. A superseded reading has been
     * replaced, and letting it keep a `text_simhash` would have every
     * replacement report itself as a duplicate of the page it replaced, while
     * keeping its `ocr_text` would leave the document findable by a scan
     * nobody can open any more.
     */
    public function up(): void
    {
        Schema::create('document_attachment_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_attachment_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('filename');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('checksum');
            // When this file was uploaded, and when it stopped being the
            // current one. `created_at` cannot stand in for either: the row is
            // written at the moment the file is superseded, which is the end of
            // its life as the current file rather than the start.
            $table->timestamp('uploaded_at');
            $table->timestamp('superseded_at');
            $table->timestamps();

            // Carries `size` for the same reason the attachments table's does
            // (see the 2026_09_01 covering-index migration): the workspace
            // storage total sums this column across a whole workspace, and
            // without it every superseded row has to be read off disk to add
            // one number up. Reading an attachment's chain uses the same
            // index on its leftmost column, and orders the handful of rows it
            // finds in memory.
            //
            // Named by hand: the generated name runs past MySQL's
            // 64-character ceiling for an identifier.
            $table->index(['document_attachment_id', 'size'], 'attachment_versions_size_index');
        });

        Schema::table('document_attachments', function (Blueprint $table) {
            // When the file currently held here was uploaded. `created_at` is
            // when the attachment was created, which is only the same thing
            // until the first replacement.
            $table->timestamp('file_uploaded_at')->nullable()->after('checksum');
        });

        // InnoDB auto-creates an index for the foreign key, then discards it
        // once one with `document_attachment_id` leftmost exists. Dropping it
        // unconditionally fails where that already happened.
        if (Schema::hasIndex('document_attachment_versions', 'document_attachment_versions_document_attachment_id_foreign')) {
            Schema::table('document_attachment_versions', function (Blueprint $table) {
                $table->dropIndex('document_attachment_versions_document_attachment_id_foreign');
            });
        }

        DB::table('document_attachments')->update(['file_uploaded_at' => DB::raw('created_at')]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->dropColumn('file_uploaded_at');
        });

        Schema::dropIfExists('document_attachment_versions');
    }
};
