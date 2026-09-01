<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Enums\TaskType;
use App\Enums\WorkspaceRole;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceLimit;
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
            'files' => [$file],
        ]);

        $response->assertRedirect();

        $attachment = DocumentAttachment::query()->where('document_id', $document->id)->firstOrFail();

        $this->assertSame($member->user->id, $attachment->uploaded_by);
        $this->assertSame('scan.pdf', $attachment->filename);
        $this->assertSame('local', $attachment->disk);
        $this->assertNotEmpty($attachment->checksum);
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_several_files_can_be_uploaded_in_one_request()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        $response = $this->actingAs($member->user)->post(route('attachments.store', $document), [
            'files' => [
                UploadedFile::fake()->create('page-1.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('page-2.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('page-3.pdf', 10, 'application/pdf'),
            ],
        ]);

        $response->assertRedirect();
        $this->assertDatabaseCount('document_attachments', 3);

        $this->assertSame(
            ['page-1.pdf', 'page-2.pdf', 'page-3.pdf'],
            DocumentAttachment::query()->orderBy('created_at')->pluck('filename')->all(),
        );

        // One extraction task per attachment, as ARC-84 established.
        $this->assertSame(3, Task::query()->where('type', TaskType::AttachmentTextExtraction)->count());
    }

    public function test_a_batch_that_would_pass_the_attachment_limit_stores_nothing()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);
        WorkspaceLimit::factory()->for($workspace)->create(['attachments' => 2]);

        $response = $this->actingAs($member->user)->post(route('attachments.store', $document), [
            'files' => [
                UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
            ],
        ]);

        $response->assertSessionHasErrors('files');

        // All or nothing: the two that would have fit are not stored either.
        $this->assertDatabaseCount('document_attachments', 0);
        $this->assertSame(0, Task::query()->count());
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_the_attachment_limit_message_names_how_many_still_fit()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);
        WorkspaceLimit::factory()->for($workspace)->create(['attachments' => 2]);

        $response = $this->actingAs($member->user)->post(route('attachments.store', $document), [
            'files' => [
                UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('c.pdf', 10, 'application/pdf'),
            ],
        ]);

        $response->assertSessionHasErrors([
            'files' => __('document.attachment_limit_remaining_other', ['count' => 2]),
        ]);
    }

    public function test_the_attachment_limit_message_is_singular_for_one_free_slot()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);
        WorkspaceLimit::factory()->for($workspace)->create(['attachments' => 1]);

        $response = $this->actingAs($member->user)->post(route('attachments.store', $document), [
            'files' => [
                UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
            ],
        ]);

        $response->assertSessionHasErrors([
            'files' => __('document.attachment_limit_remaining_one'),
        ]);
    }

    public function test_a_batch_that_would_pass_the_storage_limit_stores_nothing()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        // Room for one of the two files, but not for both together.
        WorkspaceLimit::factory()->for($workspace)->create(['storage_bytes' => 15 * 1024]);

        $response = $this->actingAs($member->user)->post(route('attachments.store', $document), [
            'files' => [
                UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('b.pdf', 10, 'application/pdf'),
            ],
        ]);

        $response->assertSessionHasErrors('files');
        $this->assertDatabaseCount('document_attachments', 0);
        $this->assertEmpty(Storage::disk('local')->allFiles());
    }

    public function test_one_oversized_file_rejects_the_whole_batch()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        $response = $this->actingAs($member->user)->post(route('attachments.store', $document), [
            'files' => [
                UploadedFile::fake()->create('fine.pdf', 10, 'application/pdf'),
                UploadedFile::fake()->create('huge.pdf', 60 * 1024, 'application/pdf'),
            ],
        ]);

        $response->assertSessionHasErrors('files.1');
        $this->assertDatabaseCount('document_attachments', 0);
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
            'files' => [UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf')],
        ]);

        $response->assertForbidden();
        $this->assertDatabaseCount('document_attachments', 0);
    }
}
