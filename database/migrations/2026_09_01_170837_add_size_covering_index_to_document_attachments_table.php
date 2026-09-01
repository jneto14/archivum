<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Make the workspace storage total readable from an index instead of the rows.
 *
 * `CalculateWorkspaceUsage::storageBytes()` sums `size` over every attachment
 * belonging to a workspace's documents, and it runs on the dashboard and the
 * Usage & limits page. MySQL already drove that query from
 * `documents_workspace_id_foreign`, so it only ever touched the workspace's own
 * attachments — but `size` sits in no index, so every matching row had to be
 * read off disk to add one number up.
 *
 * Widening the `document_id` index to carry `size` makes the sum index-only.
 * Measured on MySQL 8.4 with the workspace held at 5,000 attachments while the
 * rest of the table grew to 200,000: 11.6ms -> 1.6ms, flat in both cases
 * against data belonging to other workspaces. At 30,000 attachments in one
 * workspace it is 71.5ms -> 7.7ms.
 *
 * `document_id` is the leftmost column of the new index, so the foreign key
 * stays satisfied. On a fresh MySQL 8.4 database InnoDB then discards the index
 * it had auto-created for that key, which is why the drop below is conditional:
 * unconditionally dropping it fails on a fresh install and leaves a redundant
 * index behind on one that upgrades.
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::table('document_attachments', function (Blueprint $table): void {
            $table->index(['document_id', 'size'], 'document_attachments_document_id_size_index');
        });

        if (Schema::hasIndex('document_attachments', 'document_attachments_document_id_foreign')) {
            Schema::table('document_attachments', function (Blueprint $table): void {
                $table->dropIndex('document_attachments_document_id_foreign');
            });
        }
    }

    public function down(): void
    {
        if (!Schema::hasIndex('document_attachments', 'document_attachments_document_id_foreign')) {
            Schema::table('document_attachments', function (Blueprint $table): void {
                $table->index('document_id', 'document_attachments_document_id_foreign');
            });
        }

        Schema::table('document_attachments', function (Blueprint $table): void {
            $table->dropIndex('document_attachments_document_id_size_index');
        });
    }
};
