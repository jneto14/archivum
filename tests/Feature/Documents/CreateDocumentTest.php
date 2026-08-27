<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Tag;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_member_can_create_a_document_with_metadata_and_tags()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $tag = Tag::factory()->for($workspace)->create();

        $response = $this->actingAs($member->user)->post(route('documents.store', $workspace), [
            'document_type_id' => $type->id,
            'title' => 'Invoice #FT2026/1234',
            'document_date' => '2026-08-20',
            'metadata' => ['supplier' => 'Example Lda', 'vehicle_registration' => '12-AA-34'],
            'tag_ids' => [$tag->id],
        ]);

        $document = Document::query()->where('title', 'Invoice #FT2026/1234')->firstOrFail();

        $response->assertRedirect(route('documents.show', $document));

        $this->assertSame($workspace->id, $document->workspace_id);
        $this->assertSame($member->user->id, $document->created_by);
        $this->assertSame('Example Lda', $document->metadata['supplier']);
        $this->assertTrue($document->tags->contains($tag));
    }

    public function test_document_type_from_another_workspace_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $foreignType = DocumentType::factory()->create();

        $response = $this->actingAs($member->user)->post(route('documents.store', $workspace), [
            'document_type_id' => $foreignType->id,
            'title' => 'Hijacked',
        ]);

        $response->assertNotFound();
        $this->assertDatabaseMissing('documents', ['title' => 'Hijacked']);
    }

    public function test_non_member_cannot_create_a_document()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();

        $response = $this->actingAs($outsider->user)->post(route('documents.store', $workspace), [
            'document_type_id' => $type->id,
            'title' => 'Should not exist',
        ]);

        $response->assertForbidden();
    }
}
