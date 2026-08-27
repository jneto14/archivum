<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentFormPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_view_the_create_form()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $this->actingAs($member->user)
            ->get(route('documents.create', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('documents/form')
                ->where('document', null),
            );
    }

    public function test_non_member_cannot_view_the_create_form()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->get(route('documents.create', $workspace))
            ->assertForbidden();
    }

    public function test_creator_can_view_the_edit_form()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);

        $this->actingAs($creator->user)
            ->get(route('documents.edit', $document))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('documents/form')
                ->where('document.id', $document->id),
            );
    }

    public function test_non_creator_member_cannot_view_the_edit_form()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $otherMember = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Original', null, null);

        $this->actingAs($otherMember->user)
            ->get(route('documents.edit', $document))
            ->assertForbidden();
    }
}
