<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('organization_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('scheme_id')->constrained('organization_schemes')->cascadeOnDelete();
            $table->string('matcher_key');
            $table->string('matcher_value');
            $table->foreignUuid('target_level_id')->constrained('organization_levels')->cascadeOnDelete();
            $table->string('preferred_value');
            $table->timestamps();

            $table->unique(['scheme_id', 'matcher_key', 'matcher_value']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_rules');
    }
};
