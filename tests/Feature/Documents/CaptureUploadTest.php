<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\TrashAttachment;
use App\Actions\Documents\UploadAttachment;
use App\Enums\CaptureSessionStatus;
use App\Models\DocumentAttachment;
use App\Models\DocumentCaptureSession;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\SignedLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CaptureUploadTest extends TestCase
{
    use RefreshDatabase;

    private function signedShowUrl(DocumentCaptureSession $session): string
    {
        return SignedLink::temporary(
            'capture.show',
            $session->expires_at,
            ['captureSession' => $session->id],
        );
    }

    public function test_a_valid_signed_link_shows_the_capture_page()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create()->user;
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Invoice', null, null);
        $session = DocumentCaptureSession::factory()->for($document)->for($creator, 'creator')->create();

        $response = $this->get($this->signedShowUrl($session));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->component('capture/show')
            ->where('active', true)
            ->where('document_title', $document->title));
    }

    public function test_an_unsigned_link_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create()->user;
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Invoice', null, null);
        $session = DocumentCaptureSession::factory()->for($document)->for($creator, 'creator')->create();

        $response = $this->get(route('capture.show', $session));

        $response->assertForbidden();
    }

    public function test_a_link_past_its_own_expiry_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create()->user;
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Invoice', null, null);
        $session = DocumentCaptureSession::factory()->for($document)->for($creator, 'creator')->create();

        $url = $this->signedShowUrl($session);

        $this->travelTo($session->expires_at->addMinute());

        $response = $this->get($url);

        $response->assertForbidden();
    }

    public function test_uploading_a_photo_through_a_signed_link_attaches_it_to_the_document()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create()->user;
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Invoice', null, null);
        $session = DocumentCaptureSession::factory()->for($document)->for($creator, 'creator')->create();

        $response = $this->post($this->signedShowUrl($session), [
            'files' => [UploadedFile::fake()->image('page-1.jpg')],
        ]);

        $response->assertRedirect();

        $attachment = DocumentAttachment::query()->where('document_id', $document->id)->firstOrFail();

        // No phone session exists to attribute this to, so it's the desktop
        // user who started the pairing — the same person the session was
        // opened for.
        $this->assertSame($creator->id, $attachment->uploaded_by);
        $this->assertSame(1, $session->fresh()->photos_count);
    }

    public function test_a_session_aimed_at_an_attachment_replaces_it_and_ends()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create()->user;
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Invoice', null, null);
        $attachment = app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->image('dark.jpg'),
            $creator,
        );

        $session = DocumentCaptureSession::factory()
            ->for($document)
            ->for($creator, 'creator')
            ->create(['replaces_attachment_id' => $attachment->id]);

        $response = $this->post($this->signedShowUrl($session), [
            'files' => [UploadedFile::fake()->image('page-1.jpg')],
        ]);

        $response->assertRedirect();

        // One attachment still, now holding the re-shot page, with the badly
        // lit one behind it.
        $this->assertDatabaseCount('document_attachments', 1);
        $this->assertSame('page-1.jpg', $attachment->fresh()->filename);
        $this->assertSame('dark.jpg', $attachment->versions()->sole()->filename);

        // There is one file to replace, so a second photo would have nothing
        // left to act on.
        $this->assertSame(CaptureSessionStatus::Completed, $session->fresh()->status);
    }

    public function test_the_capture_page_names_the_file_it_was_opened_to_re_shoot()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create()->user;
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Invoice', null, null);
        $attachment = app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->image('dark.jpg'),
            $creator,
        );

        $session = DocumentCaptureSession::factory()
            ->for($document)
            ->for($creator, 'creator')
            ->create(['replaces_attachment_id' => $attachment->id]);

        $this->get($this->signedShowUrl($session))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('capture/show')
                ->where('replaces_filename', 'dark.jpg'));
    }

    public function test_a_session_whose_target_was_trashed_goes_back_to_adding_attachments()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create()->user;
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Invoice', null, null);
        $attachment = app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->image('dark.jpg'),
            $creator,
        );

        $session = DocumentCaptureSession::factory()
            ->for($document)
            ->for($creator, 'creator')
            ->create(['replaces_attachment_id' => $attachment->id]);

        // Trashing is a soft delete, so the foreign key never fires — the
        // relation is what drops the target, and a live QR code must keep
        // working rather than failing on a row it can no longer see.
        app(TrashAttachment::class)->handle($attachment);

        $this->post($this->signedShowUrl($session), [
            'files' => [UploadedFile::fake()->image('page-1.jpg')],
        ])->assertRedirect();

        $this->assertSame(1, DocumentAttachment::query()->where('document_id', $document->id)->count());
        $this->assertSame(CaptureSessionStatus::Active, $session->fresh()->status);
    }

    public function test_uploading_through_a_cancelled_session_is_silently_ignored()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create()->user;
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Invoice', null, null);
        $session = DocumentCaptureSession::factory()->cancelled()->for($document)->for($creator, 'creator')->create();

        $response = $this->post($this->signedShowUrl($session), [
            'files' => [UploadedFile::fake()->image('page-1.jpg')],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('document_attachments', 0);
        $this->assertSame(0, $session->fresh()->photos_count);
    }

    public function test_a_signature_that_outlives_the_sessions_own_expiry_still_rejects_the_upload()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create()->user;
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Invoice', null, null);
        // Already past its own expiry, but the signature below outlives it —
        // this can't happen through the app's own QR code (both come from the
        // same `expires_at`), but the model's own expiry check must still
        // hold as the last line of defence.
        $session = DocumentCaptureSession::factory()->expired()->for($document)->for($creator, 'creator')->create();

        $url = SignedLink::temporary('capture.show', now()->addDay(), ['captureSession' => $session->id]);

        $response = $this->post($url, [
            'files' => [UploadedFile::fake()->image('page-1.jpg')],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('document_attachments', 0);
    }

    public function test_tapping_done_marks_the_session_completed()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create()->user;
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Invoice', null, null);
        $session = DocumentCaptureSession::factory()->for($document)->for($creator, 'creator')->create();

        $response = $this->post($this->signedShowUrl($session), ['done' => '1']);

        $response->assertRedirect();
        $this->assertSame(CaptureSessionStatus::Completed, $session->fresh()->status);
    }

    public function test_the_page_reports_why_the_session_ended()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create()->user;
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator, $type, 'Invoice', null, null);
        $session = DocumentCaptureSession::factory()->completed()->for($document)->for($creator, 'creator')->create();

        $response = $this->get($this->signedShowUrl($session));

        $response->assertInertia(fn (Assert $page) => $page
            ->component('capture/show')
            ->where('active', false)
            ->where('status', 'completed'));
    }
}
