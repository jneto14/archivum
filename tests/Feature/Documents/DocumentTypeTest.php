<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentTypeTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_list_document_types_but_only_admins_can_manage()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create(['name' => 'Invoice', 'key' => 'invoice']);
        app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Doc', null, null);

        $this->actingAs($member->user)
            ->get(route('document-types.index', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('document-types/index')
                ->has('documentTypes', 1)
                ->where('documentTypes.0.name', 'Invoice')
                ->where('documentTypes.0.documents_count', 1)
                ->where('canManage', false),
            );
    }

    public function test_non_member_cannot_list_document_types()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->get(route('document-types.index', $workspace))
            ->assertForbidden();
    }

    public function test_admin_can_create_a_document_type()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->post(route('document-types.store', $workspace), [
            'name' => 'Contract',
            'key' => 'contract',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('document_types', ['workspace_id' => $workspace->id, 'name' => 'Contract', 'key' => 'contract']);
    }

    public function test_non_admin_member_cannot_create_a_document_type()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($member->user)->post(route('document-types.store', $workspace), [
            'name' => 'Contract',
            'key' => 'contract',
        ]);

        $response->assertForbidden();
    }

    public function test_admin_can_rename_a_document_type()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create(['name' => 'Old', 'key' => 'old']);

        $response = $this->actingAs($admin->user)->patch(route('document-types.update', $type), [
            'name' => 'New',
            'key' => 'new',
        ]);

        $response->assertRedirect();
        $this->assertSame('New', $type->fresh()->name);
        $this->assertSame('new', $type->fresh()->key);
    }

    public function test_updating_to_a_key_already_used_in_the_workspace_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'contract']);

        $response = $this->actingAs($admin->user)->patch(route('document-types.update', $type), [
            'name' => $type->name,
            'key' => 'invoice',
        ]);

        $response->assertSessionHasErrors('key');
        $this->assertSame('contract', $type->fresh()->key);
    }

    public function test_admin_can_delete_an_unused_document_type()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();

        $response = $this->actingAs($admin->user)->delete(route('document-types.destroy', $type));

        $response->assertRedirect();
        $this->assertDatabaseMissing('document_types', ['id' => $type->id]);
    }

    public function test_document_type_with_documents_assigned_cannot_be_deleted()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();
        app(CreateDocument::class)->handle($workspace, $admin->user, $type, 'Doc', null, null);

        $response = $this->actingAs($admin->user)->delete(route('document-types.destroy', $type));

        $response->assertSessionHasErrors('document_type');
        $this->assertDatabaseHas('document_types', ['id' => $type->id]);
    }

    public function test_non_admin_member_cannot_delete_a_document_type()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();

        $response = $this->actingAs($member->user)->delete(route('document-types.destroy', $type));

        $response->assertForbidden();
    }

    /**
     * By how much each holds, which is a count rather than a stored column.
     */
    public function test_types_can_be_ordered_by_how_many_documents_they_hold()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $busy = DocumentType::factory()->for($workspace)->create(['name' => 'Zebra']);
        $quiet = DocumentType::factory()->for($workspace)->create(['name' => 'Aardvark']);

        Document::factory()->for($workspace)->for($busy, 'documentType')->count(3)->create();
        Document::factory()->for($workspace)->for($quiet, 'documentType')->create();

        $this->actingAs($member->user)
            ->get(route('document-types.index', [
                'workspace' => $workspace,
                'sort' => 'documents_count',
                'direction' => 'desc',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort.key', 'documents_count')
                ->where('documentTypes.0.name', 'Zebra')
                ->where('documentTypes.1.name', 'Aardvark'),
            );
    }
}
