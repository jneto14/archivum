<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\UploadAttachment;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    public function test_an_admin_gets_no_suggestions_while_the_workspace_has_no_scheme()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $admin->user, $type, 'Original', null, null);

        // A workspace that has not configured its archive yet: the user may
        // file, but there is nowhere to suggest.
        $this->actingAs($admin->user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('canFile', true)
                ->where('locationSuggestions', []),
            );
    }

    public function test_an_attachment_is_shown_with_who_uploaded_it()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Original', null, null);

        app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
            $member->user,
        );

        $this->actingAs($member->user)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('document.attachments.0.filename', 'scan.pdf')
                ->where('document.attachments.0.uploader.id', $member->user_id)
                ->where('document.attachments.0.uploader.name', $member->user->name),
            );
    }
}
