<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Actions\Documents\RestoreAttachmentVersion;
use App\Http\Controllers\Controller;
use App\Models\DocumentAttachmentVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The files an attachment used to hold. Every action here authorizes against
 * the attachment rather than the version: a version has no standing of its own
 * — whoever may read the attachment may read what it used to say, and whoever
 * may change it may change which file that is (ARC-124).
 */
class AttachmentVersionController extends Controller
{
    /**
     * Stream a superseded file as a download.
     *
     * Download only, with no inline sibling. The preview dialog is for
     * deciding what a file says before acting on it, and the action available
     * here is restoring — which puts the file where the dialog can be pointed
     * at it in the ordinary way.
     *
     * @param DocumentAttachmentVersion $version The version whose stored file should be downloaded.
     *
     * @return StreamedResponse A streamed download of the superseded file.
     *
     * @throws AuthorizationException If the current user cannot view the attachment holding $version.
     */
    public function show(DocumentAttachmentVersion $version): StreamedResponse
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
     * @param DocumentAttachmentVersion $version The version to restore.
     * @param Request $request The incoming request; used to resolve the current user.
     * @param RestoreAttachmentVersion $action Swaps the files and queues a fresh reading.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update the attachment holding $version.
     */
    public function restore(DocumentAttachmentVersion $version, Request $request, RestoreAttachmentVersion $action): RedirectResponse
    {
        $this->authorize('update', $version->attachment);

        $action->handle($version, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.version_restored')]);

        return back();
    }
}
