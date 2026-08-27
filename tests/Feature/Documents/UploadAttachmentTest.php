<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Enums\WorkspaceRole;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class UploadAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_member_can_upload_an_attachment_to_a_document()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        $file = UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf');

        $response = $this->actingAs($member->user)->post(route('attachments.store', $document), [
            'file' => $file,
        ]);

        $response->assertRedirect();

        $attachment = DocumentAttachment::query()->where('document_id', $document->id)->firstOrFail();

        $this->assertSame($member->user->id, $attachment->uploaded_by);
        $this->assertSame('scan.pdf', $attachment->filename);
        $this->assertSame('local', $attachment->disk);
        $this->assertNotEmpty($attachment->checksum);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_non_member_cannot_upload_an_attachment()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($outsider->user)->post(route('attachments.store', $document), [
            'file' => UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('document_attachments', 0);
    }
}
