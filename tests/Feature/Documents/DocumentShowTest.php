<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentShowTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_can_view_a_document()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Original', null, null);

        $this->actingAs($member->user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('documents/show')
                ->where('document.id', $document->id)
                ->where('canFile', false)
                ->where('locationSuggestions', []),
            );
    }

    public function test_outsider_cannot_view_a_document()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $outsider->user, $type, 'Original', null, null);

        $this->actingAs($outsider->user)
            ->get(route('documents.show', $document))
            ->assertForbidden();
    }

    public function test_admin_with_a_scheme_configured_sees_location_suggestions()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create(['key' => 'invoice']);
        $document = app(CreateDocument::class)->handle($workspace, $admin->user, $type, 'Original', null, null);

        $scheme = app(CreateScheme::class)->handle($workspace, 'Scheme', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);

        $this->actingAs($admin->user)
            ->get(route('documents.show', $document))
            ->assertInertia(fn (Assert $page) => $page
                ->where('canFile', true)
                ->has('locationSuggestions', 1)
                ->where('locationSuggestions.0.recommended', true),
            );
    }
}
