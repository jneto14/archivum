<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\DocumentCaptureSession;
use App\Support\SignedLink;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A pairing between a document and a phone about to photograph a page of it.
 *
 * @mixin DocumentCaptureSession
 */
class CaptureSessionResource extends JsonResource
{
    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, mixed> The session's state and the link the phone acts on.
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'document_id' => $this->document_id,
            'status' => $this->status->value,
            'is_active' => $this->isActive(),
            // The attachment this session is aimed at replacing, if it is
            // aimed at one. Such a session takes a single photo and ends —
            // there is one file to replace, so a second would have nothing
            // left to act on (ARC-124).
            'replaces_attachment_id' => $this->replaces_attachment_id,
            'expires_at' => $this->expires_at->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            // The signed link the phone loads and uploads through. The
            // interface renders this as a QR code; a client is handed the URL
            // itself, which is the thing the code was only ever carrying.
            'pairing_url' => SignedLink::temporary(
                'capture.show',
                $this->expires_at,
                ['captureSession' => $this->id],
            ),
        ];
    }
}
