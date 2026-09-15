<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Documents\RestoreAttachmentVersion;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\AttachmentResource;
use App\Http\Resources\Api\V1\AttachmentVersionResource;
use App\Models\DocumentAttachment;
use App\Models\DocumentAttachmentVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The files an attachment used to hold.
 *
 * Every action authorizes against the attachment rather than the version: a
 * version has no standing of its own — whoever may read the attachment may
 * read what it used to say, and whoever may change it may change which file
 * that is (ARC-124).
 */
class AttachmentVersionController extends Controller
{
    /**
     * List the files an attachment has held and no longer holds.
     *
     * Newest replacement first, which is the order the history is read in. The
     * current file is not among them — it is the attachment.
     *
     * @param DocumentAttachment $attachment The attachment whose history is listed.
     *
     * @return AnonymousResourceCollection The superseded files, newest first.
     *
     * @throws AuthorizationException If the token's user cannot view $attachment.
     */
    public function index(DocumentAttachment $attachment): AnonymousResourceCollection
    {
        $this->authorize('view', $attachment);

        return AttachmentVersionResource::collection(
            $attachment->versions()->with('uploader')->get(),
        );
    }

    /**
     * Download a superseded file.
     *
     * Download only, with no inline sibling. Previewing is for deciding what a
     * file says before acting on it, and the action available here is
     * restoring — which puts the file where the ordinary preview can reach it.
     *
     * @param DocumentAttachmentVersion $version The version whose stored file is downloaded.
     *
     * @return StreamedResponse A streamed download of the superseded file.
     *
     * @throws AuthorizationException If the token's user cannot view the attachment holding $version.
     */
    public function download(DocumentAttachmentVersion $version): StreamedResponse
    {
        $this->authorize('view', $version->attachment);

        return Storage::disk($version->disk)->download(
            $version->path,
            $version->filename,
            ['X-Content-Type-Options' => 'nosniff'],
        );
    }

    /**
     * Make a superseded file current again, pushing the one it displaces into
     * the history behind it.
     *
     * A swap, not an upload: the restored file keeps the path it already has
     * on disk, so the workspace is not charged twice for bytes it already
     * holds.
     *
     * @param Request $request The incoming request, used to resolve the acting user.
     * @param DocumentAttachmentVersion $version The version to restore.
     * @param RestoreAttachmentVersion $action Swaps the files and queues a fresh reading.
     *
     * @return AttachmentResource The attachment, now holding the restored file.
     *
     * @throws AuthorizationException If the token's user cannot update the attachment holding $version.
     */
    public function restore(Request $request, DocumentAttachmentVersion $version, RestoreAttachmentVersion $action): AttachmentResource
    {
        $attachment = $version->attachment;

        $this->authorize('update', $attachment);

        $action->handle($version, $request->user());

        return new AttachmentResource(
            $attachment->refresh()->load(['uploader', 'duplicateOf', 'versions.uploader']),
        );
    }
}
