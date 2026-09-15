<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Actions\Documents\TrashAttachment;
use App\Actions\Documents\TrashDocument;
use App\Actions\Documents\UploadAttachment;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TrashApiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private DocumentType $type;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('archivum.attachments.disk'));

        $this->workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($this->workspace)->create(['role' => WorkspaceRole::Admin]);
        $this->admin = $member->user;
        $this->type = DocumentType::factory()->for($this->workspace)->create();
        $this->token = $this->admin->createToken('CLI')->plainTextToken;
    }

    private function document(): Document
    {
        return Document::factory()->for($this->workspace)->for($this->type)->create();
    }

    private function attachmentOn(Document $document, string $name = 'scan.pdf'): DocumentAttachment
    {
        return app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->create($name, 10),
            $this->admin,
        );
    }

    public function test_trashed_documents_are_listed_with_the_retention_window()
    {
        $document = $this->document();
        app(TrashDocument::class)->handle($document);

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/trash/documents")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath(
                'meta.retention_days',
                (int) config('archivum.trash.retention_days'),
            );
    }

    /**
     * `deleted_at` is only present on something that is actually in the trash.
     */
    public function test_a_live_document_does_not_carry_a_deleted_at()
    {
        $document = $this->document();

        $this->withToken($this->token)
            ->getJson("/api/v1/documents/{$document->id}")
            ->assertOk()
            ->assertJsonMissingPath('data.deleted_at');
    }

    public function test_a_trashed_document_reports_when_it_was_trashed()
    {
        $document = $this->document();
        app(TrashDocument::class)->handle($document);

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/trash/documents")
            ->assertOk()
            ->assertJsonPath('data.0.deleted_at', fn (?string $at): bool => $at !== null);
    }

    /**
     * The interface hides these, because the document's own row already stands
     * for them. A client has no such row to read.
     */
    public function test_an_attachment_trashed_with_its_document_is_still_listed()
    {
        $document = $this->document();
        $this->attachmentOn($document);
        app(TrashDocument::class)->handle($document);

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/trash/attachments")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_a_document_can_be_taken_back_out_of_the_trash()
    {
        $document = $this->document();
        app(TrashDocument::class)->handle($document);

        $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/trash/documents/{$document->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $document->id);

        $this->assertNotSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_purging_a_document_destroys_it_and_its_files()
    {
        $document = $this->document();
        $attachment = $this->attachmentOn($document);
        app(TrashDocument::class)->handle($document);

        $this->withToken($this->token)
            ->deleteJson("/api/v1/workspaces/{$this->workspace->id}/trash/documents/{$document->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        Storage::disk($attachment->disk)->assertMissing($attachment->path);
    }

    public function test_an_attachment_can_be_restored_and_purged()
    {
        $document = $this->document();
        $attachment = $this->attachmentOn($document);
        app(TrashAttachment::class)->handle($attachment);

        $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/trash/attachments/{$attachment->id}")
            ->assertOk();
        $this->assertNotSoftDeleted('document_attachments', ['id' => $attachment->id]);

        app(TrashAttachment::class)->handle($attachment->refresh());

        $this->withToken($this->token)
            ->deleteJson("/api/v1/workspaces/{$this->workspace->id}/trash/attachments/{$attachment->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('document_attachments', ['id' => $attachment->id]);
        Storage::disk($attachment->disk)->assertMissing($attachment->path);
    }

    public function test_the_trash_can_be_emptied()
    {
        $document = $this->document();
        $this->attachmentOn($document);
        app(TrashDocument::class)->handle($document);

        $this->withToken($this->token)
            ->deleteJson("/api/v1/workspaces/{$this->workspace->id}/trash")
            ->assertNoContent();

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
    }

    /**
     * Trashing is reversible and purging is not, so purging answers to a
     * narrower policy than the delete that put it there.
     */
    public function test_a_plain_member_may_restore_but_not_purge_what_they_trashed()
    {
        $member = WorkspaceUser::factory()->for($this->workspace)->create(['role' => WorkspaceRole::User]);
        $document = Document::factory()
            ->for($this->workspace)
            ->for($this->type)
            ->create(['created_by' => $member->user->id]);
        app(TrashDocument::class)->handle($document);

        $token = $member->user->createToken('CLI')->plainTextToken;

        $this->withToken($token)
            ->deleteJson("/api/v1/workspaces/{$this->workspace->id}/trash/documents/{$document->id}")
            ->assertForbidden();

        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/trash/documents/{$document->id}")
            ->assertOk();
    }

    public function test_a_trashed_id_from_another_workspace_is_not_found()
    {
        $stranger = Workspace::factory()->create();
        $strangerType = DocumentType::factory()->for($stranger)->create();
        $theirs = Document::factory()->for($stranger)->for($strangerType)->create();
        app(TrashDocument::class)->handle($theirs);

        $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/trash/documents/{$theirs->id}")
            ->assertNotFound();
    }

    /**
     * A trashed attachment arrives with no document row beside it, so the
     * filename alone does not say what is about to be lost. The document is
     * very often in the trash too, which is why the listing lifts its
     * soft-delete scope rather than handing back a row naming nothing.
     */
    public function test_a_trashed_attachment_names_the_document_it_came_from()
    {
        $document = $this->document();
        $attachment = $this->attachmentOn($document);
        app(TrashDocument::class)->handle($document);

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/trash/attachments")
            ->assertOk()
            ->assertJsonPath('data.0.id', $attachment->id)
            ->assertJsonPath('data.0.document.id', $document->id)
            ->assertJsonPath('data.0.document.title', $document->title);
    }
}
