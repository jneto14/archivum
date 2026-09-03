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
        Schema::table('document_attachments', function (Blueprint $table) {
            // Signed on purpose: a SimHash is 64 bits of which the sign bit is
            // one, and PHP's int is exactly a signed 64-bit value. Storing it
            // unsigned would mean carrying it as a string and converting on
            // every comparison.
            $table->bigInteger('text_simhash')->nullable()->after('ocr_extracted_at');
            $table->foreignUuid('duplicate_of_attachment_id')
                ->nullable()
                ->after('text_simhash')
                ->constrained('document_attachments')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('duplicate_of_attachment_id');
            $table->dropColumn('text_simhash');
        });
    }
};
