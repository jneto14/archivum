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

class AttachmentIsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_outsider_cannot_upload_view_or_delete_another_workspaces_document_attachment()
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
            ->post(route('attachments.store', $document), ['file' => UploadedFile::fake()->create('other.pdf', 50)])
            ->assertForbidden();

        $this->actingAs($outsider->user)
            ->get(route('attachments.show', $attachment))
            ->assertForbidden();

        $this->actingAs($outsider->user)
            ->get(route('attachments.preview', $attachment))
            ->assertForbidden();

        $this->actingAs($outsider->user)
            ->delete(route('attachments.destroy', $attachment))
            ->assertForbidden();
    }
}
