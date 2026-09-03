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
        Schema::create('document_capture_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('document_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('active');
            $table->unsignedInteger('photos_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamps();

            // Looked up two ways: the phone resolves one row by id (from the
            // signed URL), the desktop looks up "the document's current
            // session" while polling. Only the second needs an index — a
            // primary-key lookup already has one.
            $table->index(['document_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_capture_sessions');
    }
};
