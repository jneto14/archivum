<?php

namespace App\Actions\Documents;

use App\Models\DocumentAttachment;
use Illuminate\Support\Facades\Storage;

class DeleteAttachment
{
    /**
     * Delete an attachment and its underlying stored file.
     */
    public function handle(DocumentAttachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);

        $attachment->delete();
    }
}
