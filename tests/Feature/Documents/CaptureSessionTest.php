<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Enums\CaptureSessionStatus;
use App\Enums\WorkspaceRole;
use App\Models\DocumentCaptureSession;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CaptureSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_member_can_start_a_capture_session()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        $response = $this->actingAs($member->user)->post(route('capture-sessions.store', $document));

        $response->assertRedirect();

        $session = DocumentCaptureSession::query()->where('document_id', $document->id)->firstOrFail();

        $this->assertSame($member->user->id, $session->created_by);
        $this->assertSame(CaptureSessionStatus::Active, $session->status);
        $this->assertSame(
            config('archivum.capture.session_ttl_minutes'),
            (int) round(now()->diffInMinutes($session->expires_at)),
        );
    }

    public function test_starting_a_new_session_cancels_the_one_already_open()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        $this->actingAs($member->user)->post(route('capture-sessions.store', $document));
        $first = DocumentCaptureSession::query()->where('document_id', $document->id)->firstOrFail();

        $this->actingAs($member->user)->post(route('capture-sessions.store', $document));

        $this->assertSame(CaptureSessionStatus::Cancelled, $first->fresh()->status);
        $this->assertSame(
            1,
            DocumentCaptureSession::query()
                ->where('document_id', $document->id)
                ->where('status', CaptureSessionStatus::Active)
                ->count(),
        );
    }

    public function test_non_member_cannot_start_a_capture_session()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($outsider->user)->post(route('capture-sessions.store', $document));

        $response->assertForbidden();
        $this->assertDatabaseCount('document_capture_sessions', 0);
    }

    public function test_workspace_member_can_fetch_the_sessions_qr_code()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);
        $session = DocumentCaptureSession::factory()->for($document)->for($member->user, 'creator')->create();

        $response = $this->actingAs($member->user)->get(route('capture-sessions.qr-code', [$document, $session]));

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('Content-Type'));
    }

    public function test_non_member_cannot_fetch_the_sessions_qr_code()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);
        $session = DocumentCaptureSession::factory()->for($document)->for($member->user, 'creator')->create();

        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($outsider->user)->get(route('capture-sessions.qr-code', [$document, $session]));

        $response->assertForbidden();
    }

    public function test_workspace_member_can_cancel_a_session()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);
        $session = DocumentCaptureSession::factory()->for($document)->for($member->user, 'creator')->create();

        $response = $this->actingAs($member->user)->post(route('capture-sessions.cancel', [$document, $session]));

        $response->assertRedirect();
        $this->assertSame(CaptureSessionStatus::Cancelled, $session->fresh()->status);
    }

    public function test_non_member_cannot_cancel_a_session()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);
        $session = DocumentCaptureSession::factory()->for($document)->for($member->user, 'creator')->create();

        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($outsider->user)->post(route('capture-sessions.cancel', [$document, $session]));

        $response->assertForbidden();
        $this->assertSame(CaptureSessionStatus::Active, $session->fresh()->status);
    }

    public function test_a_session_belonging_to_a_different_document_is_not_found()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);
        $otherDocument = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Contract', null, null);
        $session = DocumentCaptureSession::factory()->for($otherDocument, 'document')->for($member->user, 'creator')->create();

        $this->actingAs($member->user)
            ->get(route('capture-sessions.qr-code', [$document, $session]))
            ->assertNotFound();

        $this->actingAs($member->user)
            ->post(route('capture-sessions.cancel', [$document, $session]))
            ->assertNotFound();
    }
}
