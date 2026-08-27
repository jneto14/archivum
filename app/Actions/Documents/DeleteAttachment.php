<?php

declare(strict_types=1);

namespace App\Actions\Documents;

use App\Models\DocumentAttachment;
use Illuminate\Support\Facades\Storage;

class DeleteAttachment
{
    /**
     * Delete an attachment and its underlying stored file.
     *
     * @param DocumentAttachment $attachment The attachment to remove, including its stored file on disk.
     *
     * @return void No return value; the stored file and record are deleted as a side effect.
     */
    public function handle(DocumentAttachment $attachment): void
    {
        Storage::disk($attachment->disk)->delete($attachment->path);

        $attachment->delete();
    }
}
