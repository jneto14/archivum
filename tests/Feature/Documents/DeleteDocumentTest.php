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
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
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

    public function test_deleting_a_document_cascades_tags_and_locations()
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

        $this->assertDatabaseMissing('document_tags', ['document_id' => $document->id]);
        $this->assertDatabaseMissing('document_locations', ['document_id' => $document->id]);
    }

    public function test_deleting_a_document_purges_its_attachment_files_from_disk()
    {
        Storage::fake(config('archivum.attachments.disk'));
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);
        $attachment = app(UploadAttachment::class)->handle($document, UploadedFile::fake()->create('scan.pdf'), $creator->user);

        $this->actingAs($creator->user)->delete(route('documents.destroy', $document))->assertRedirect();

        Storage::disk($attachment->disk)->assertMissing($attachment->path);
    }
}
