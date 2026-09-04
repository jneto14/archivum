<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Enums\WorkspaceRole;
use App\Jobs\LearnDocumentIntakeLabels;
use App\Models\DocumentType;
use App\Models\Tag;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class UpdateDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_creator_can_update_their_own_document_and_tags_are_resynced()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $tagA = Tag::factory()->for($workspace)->create(['name' => 'A']);
        $tagB = Tag::factory()->for($workspace)->create(['name' => 'B']);

        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null, [$tagA->id]);

        $response = $this->actingAs($creator->user)->patch(route('documents.update', $document), [
            'document_type_id' => $type->id,
            'title' => 'Renamed',
            'tag_ids' => [$tagB->id],
        ]);

        $response->assertRedirect();

        $document->refresh();

        $this->assertSame('Renamed', $document->title);
        $this->assertSame(['B'], $document->tags->pluck('name')->all());
    }

    /**
     * The moment the archive learns to read. Somebody filling in a field the
     * reader missed is the only signal this feature has, and it used to be
     * collected by a weekly sweep of every document in every workspace — up to
     * a week after the edit, and at a cost that grew with the archive rather
     * than with what changed in it.
     */
    public function test_filling_in_a_field_has_the_document_read_for_new_words()
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();

        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);

        $this->actingAs($creator->user)->patch(route('documents.update', $document), [
            'document_type_id' => $type->id,
            'title' => 'Original',
            'metadata' => ['tax_id' => '501442600'],
        ]);

        Queue::assertPushed(
            LearnDocumentIntakeLabels::class,
            fn (LearnDocumentIntakeLabels $job): bool => $job->document->is($document),
        );
    }

    /**
     * Mining reads a page of text, so it runs when the fields moved and not
     * when somebody corrected a typo in the title.
     */
    public function test_renaming_a_document_teaches_nothing_and_reads_nothing()
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();

        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);

        $this->actingAs($creator->user)->patch(route('documents.update', $document), [
            'document_type_id' => $type->id,
            'title' => 'Renamed',
        ]);

        Queue::assertNotPushed(LearnDocumentIntakeLabels::class);
    }

    public function test_workspace_admin_can_update_any_members_document()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();

        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);

        $response = $this->actingAs($admin->user)->patch(route('documents.update', $document), [
            'document_type_id' => $type->id,
            'title' => 'Renamed by admin',
        ]);

        $response->assertRedirect();
        $this->assertSame('Renamed by admin', $document->fresh()->title);
    }

    public function test_non_creator_non_admin_member_cannot_update_a_document()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $otherMember = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();

        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);

        $response = $this->actingAs($otherMember->user)->patch(route('documents.update', $document), [
            'document_type_id' => $type->id,
            'title' => 'Hijacked',
        ]);

        $response->assertForbidden();
    }
}
