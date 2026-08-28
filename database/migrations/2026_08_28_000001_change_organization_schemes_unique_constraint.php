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
        Schema::table('organization_schemes', function (Blueprint $table) {
            $table->unique('workspace_id');
            $table->dropUnique(['workspace_id', 'name']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('organization_schemes', function (Blueprint $table) {
            $table->unique(['workspace_id', 'name']);
            $table->dropUnique(['workspace_id']);
        });
    }
};
