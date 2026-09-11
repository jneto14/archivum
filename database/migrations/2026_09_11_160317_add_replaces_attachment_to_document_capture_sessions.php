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
     * A pairing session gains a target. Without one it does what it always
     * did — every photo the phone sends becomes a new attachment on the
     * document. With one, the first photo replaces that attachment's file
     * instead, which is what makes re-shooting a badly lit page from the phone
     * that took it possible at all (ARC-124).
     *
     * On the session rather than on the upload, because the phone has nothing
     * to name a target with: it holds a signed link and no session of its own,
     * and the desktop is the side that knows which row the user pressed.
     *
     * `nullOnDelete` rather than cascading, so destroying the attachment for
     * good leaves the session standing instead of taking a live pairing away
     * underneath somebody. Trashing it never reaches the constraint at all —
     * it is a soft delete — but the relation drops it just the same, and
     * either way the session falls back to behaving like an ordinary one.
     */
    public function up(): void
    {
        Schema::table('document_capture_sessions', function (Blueprint $table) {
            $table->foreignUuid('replaces_attachment_id')
                ->nullable()
                ->after('created_by')
                ->constrained('document_attachments')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_capture_sessions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_attachment_id');
        });
    }
};
