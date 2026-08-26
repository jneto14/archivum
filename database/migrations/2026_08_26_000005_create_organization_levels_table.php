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
        Schema::create('organization_levels', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('scheme_id')->constrained('organization_schemes')->cascadeOnDelete();
            $table->string('name');
            $table->string('key');
            $table->unsignedInteger('position');
            $table->unsignedInteger('capacity')->nullable();
            $table->string('value_strategy')->default('manual');
            $table->json('display_settings')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['scheme_id', 'key']);
            $table->unique(['scheme_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('organization_levels');
    }
};
