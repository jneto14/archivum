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
        Schema::create('organization_nodes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('level_id')->constrained('organization_levels')->cascadeOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained('organization_nodes')->cascadeOnDelete();
            $table->string('value');
            $table->timestamps();

            $table->unique(['parent_id', 'level_id', 'value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_nodes');
    }
};
