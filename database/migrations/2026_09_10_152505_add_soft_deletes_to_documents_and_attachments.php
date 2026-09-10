<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gives documents and attachments a trash to be deleted into.
 *
 * Both are indexed on `deleted_at` rather than only carrying the column,
 * because two queries read it constantly and neither could use an index
 * otherwise: the trash listing, which is `deleted_at` not null within one
 * workspace, and the scheduled prune, which is `deleted_at` past a cutoff
 * across every workspace.
 *
 * The documents index leads with `workspace_id` so it serves both the trash
 * listing and the far more frequent opposite query — the listing of everything
 * *not* trashed in a workspace, which the soft-delete scope now appends
 * `deleted_at is null` to on every page of the archive.
 *
 * `trashed_with_document` records that an attachment went down as part of its
 * document's deletion rather than being deleted on its own, which is what a
 * restore needs in order to bring back exactly what that deletion took. The
 * obvious alternative — matching attachments whose `deleted_at` equals the
 * document's — is wrong, and provably so: `deleted_at` has one-second
 * resolution, so deleting an attachment and then its document within the same
 * second makes the two indistinguishable and the restore resurrects a scan
 * somebody had already thrown away. A flag says what happened instead of
 * inferring it from a coincidence.
 */
return new class() extends Migration
{
    /**
     * @return void
     */
    public function up(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->softDeletes();
            $table->index(['workspace_id', 'deleted_at']);
        });

        Schema::table('document_attachments', function (Blueprint $table): void {
            $table->softDeletes();
            $table->boolean('trashed_with_document')->default(false);
            $table->index('deleted_at');
        });
    }

    /**
     * @return void
     */
    public function down(): void
    {
        // Adding the composite index can make MySQL drop the one the foreign
        // key created for itself, having decided the composite backs the
        // constraint on its own. When that happens nothing else indexes
        // `workspace_id` and dropping the composite fails with "needed in a
        // foreign key constraint"; when it does not happen, recreating the
        // index unconditionally fails with "duplicate key name". Which of the
        // two occurs depends on the state MySQL found, so this asks rather
        // than assumes.
        $indexesWorkspaceIdAlone = collect(Schema::getIndexes('documents'))
            ->contains(fn (array $index): bool => $index['columns'] === ['workspace_id']);

        Schema::table('documents', function (Blueprint $table) use ($indexesWorkspaceIdAlone): void {
            if (!$indexesWorkspaceIdAlone) {
                $table->index('workspace_id', 'documents_workspace_id_foreign');
            }

            $table->dropIndex(['workspace_id', 'deleted_at']);
            $table->dropSoftDeletes();
        });

        Schema::table('document_attachments', function (Blueprint $table): void {
            $table->dropIndex(['deleted_at']);
            $table->dropColumn('trashed_with_document');
            $table->dropSoftDeletes();
        });
    }
};
