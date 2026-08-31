<?php

declare(strict_types=1);

use App\Enums\OcrStatus;
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
            $table->string('ocr_status')->default(OcrStatus::Pending->value)->after('checksum');
            $table->longText('ocr_text')->nullable()->after('ocr_status');
            $table->text('ocr_error')->nullable()->after('ocr_text');
            $table->timestamp('ocr_extracted_at')->nullable()->after('ocr_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_attachments', function (Blueprint $table) {
            $table->dropColumn(['ocr_status', 'ocr_text', 'ocr_error', 'ocr_extracted_at']);
        });
    }
};
