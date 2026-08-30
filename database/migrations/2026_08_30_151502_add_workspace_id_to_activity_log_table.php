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
     * Deliberately not a foreign key: an audit trail must survive the deletion
     * of what it's auditing — a workspace's own "deleted" activity, or a
     * cascade-deleted subject's, needs to persist even though the workspace
     * row it points to is gone by the time the activity is logged. Spatie's
     * own subject/causer morph columns are unconstrained for the same reason.
     */
    public function up(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->uuid('workspace_id')->nullable()->after('id')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('activity_log', function (Blueprint $table) {
            $table->dropIndex(['workspace_id']);
            $table->dropColumn('workspace_id');
        });
    }
};
