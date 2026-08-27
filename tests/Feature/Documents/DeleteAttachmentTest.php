<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\UploadAttachment;
use App\Enums\WorkspaceRole;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteAttachmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_uploader_can_delete_their_own_attachment()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $attachment = $this->uploadAttachment($workspace, $member->user);

        $response = $this->actingAs($member->user)->delete(route('attachments.destroy', $attachment));

        $response->assertRedirect();
        $this->assertDatabaseMissing('document_attachments', ['id' => $attachment->id]);
    }

    public function test_admin_can_delete_another_members_attachment()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $attachment = $this->uploadAttachment($workspace, $member->user);

        $response = $this->actingAs($admin->user)->delete(route('attachments.destroy', $attachment));

        $response->assertRedirect();
        $this->assertDatabaseMissing('document_attachments', ['id' => $attachment->id]);
    }

    public function test_non_uploader_non_admin_member_cannot_delete_attachment()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $otherMember = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $attachment = $this->uploadAttachment($workspace, $member->user);

        $response = $this->actingAs($otherMember->user)->delete(route('attachments.destroy', $attachment));

        $response->assertForbidden();
        $this->assertDatabaseHas('document_attachments', ['id' => $attachment->id]);
    }

    public function test_deleting_an_attachment_removes_the_underlying_file()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $attachment = $this->uploadAttachment($workspace, $member->user);
        $path = $attachment->path;

        $this->actingAs($member->user)->delete(route('attachments.destroy', $attachment))->assertRedirect();

        Storage::disk('local')->assertMissing($path);
    }

    private function uploadAttachment(Workspace $workspace, User $uploader): DocumentAttachment
    {
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $uploader, $type, 'Invoice', null, null);

        return app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
            $uploader,
        );
    }
}
