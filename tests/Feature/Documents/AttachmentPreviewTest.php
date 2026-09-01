<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\UploadAttachment;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentPreviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_member_can_preview_an_attachment_inline()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        $attachment = app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
            $member->user,
        );

        $response = $this->actingAs($member->user)->get(route('attachments.preview', $attachment));

        $response->assertOk();
        $response->assertHeader('content-type', 'application/pdf');
        $this->assertStringStartsWith('inline;', $response->headers->get('content-disposition'));
    }

    public function test_non_member_cannot_preview_an_attachment()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        $attachment = app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
            $member->user,
        );

        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->get(route('attachments.preview', $attachment))
            ->assertForbidden();
    }

    public function test_workspace_member_can_download_an_attachment()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        $attachment = app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->create('contrato.pdf', 100, 'application/pdf'),
            $member->user,
        );

        $response = $this->actingAs($member->user)->get(route('attachments.show', $attachment));

        $response->assertOk();

        // Unlike the preview route, this one has to arrive as a saved file
        // under the name the user uploaded, not the hashed path on disk.
        $this->assertStringStartsWith('attachment;', $response->headers->get('content-disposition'));
        $this->assertStringContainsString('contrato.pdf', (string) $response->headers->get('content-disposition'));
    }

    public function test_non_member_cannot_download_an_attachment()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        $attachment = app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
            $member->user,
        );

        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->get(route('attachments.show', $attachment))
            ->assertForbidden();
    }
}
