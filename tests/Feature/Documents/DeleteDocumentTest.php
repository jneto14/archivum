<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\MoveDocument;
use App\Actions\Documents\UploadAttachment;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Tag;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_delete_their_own_document()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);

        $response = $this->actingAs($creator->user)->delete(route('documents.destroy', $document));

        $response->assertRedirect(route('documents.index', $workspace));
        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_non_creator_member_cannot_delete_a_document()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $otherMember = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);

        $response = $this->actingAs($otherMember->user)->delete(route('documents.destroy', $document));

        $response->assertForbidden();
        $this->assertDatabaseHas('documents', ['id' => $document->id]);
    }

    public function test_deleting_a_document_keeps_its_tags_and_location_history()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $tag = Tag::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null, [$tag->id]);

        $scheme = app(CreateScheme::class)->handle($workspace, 'Scheme', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $node = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');
        app(MoveDocument::class)->handle($document, $node);

        $this->actingAs($creator->user)->delete(route('documents.destroy', $document))->assertRedirect();

        // The location history is the reason the trash exists: it cannot be
        // reconstructed from the paper, so trashing must not touch it.
        $this->assertDatabaseHas('document_tags', ['document_id' => $document->id]);
        $this->assertDatabaseHas('document_locations', ['document_id' => $document->id]);
    }

    public function test_deleting_a_document_leaves_its_attachment_files_on_disk()
    {
        Storage::fake(config('archivum.attachments.disk'));
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);
        $attachment = app(UploadAttachment::class)->handle($document, UploadedFile::fake()->create('scan.pdf'), $creator->user);

        $this->actingAs($creator->user)->delete(route('documents.destroy', $document))->assertRedirect();

        // Trashing is reversible, so nothing leaves the disk until the item is
        // purged. The attachment goes down with the document, stamped with the
        // document's own timestamp so the restore can tell them apart.
        Storage::disk($attachment->disk)->assertExists($attachment->path);
        $this->assertSoftDeleted('document_attachments', ['id' => $attachment->id]);
    }
}
