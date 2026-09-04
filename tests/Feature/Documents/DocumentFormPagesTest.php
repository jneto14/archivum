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

    public function test_the_edit_form_carries_what_the_scan_suggests_for_the_empty_fields()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $creator->user, $type, 'Scan', null, null);

        // `ocr_text` is a mirror maintained by extraction, never fillable — see
        // Document::refreshOcrText().
        $document->forceFill([
            'ocr_text' => 'Fatura FT2026/1240 emitida em 20/08/2026, total a pagar 1.250,50 EUR.',
        ])->save();

        $this->actingAs($creator->user)
            ->get(route('documents.edit', $document))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('documents/form')
                ->where('metadataSuggestions.0.kind', 'document_date')
                ->where('metadataSuggestions.0.value', '2026-08-20')
                ->where('metadataSuggestions.1.kind', 'amount')
                ->where('metadataSuggestions.1.value', '1250.50'),
            );
    }

    public function test_the_create_form_has_no_suggestions_to_carry()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $this->actingAs($member->user)
            ->get(route('documents.create', $workspace))
            ->assertOk()
            // A document being registered has no attachments yet, so the prop
            // is absent rather than empty and the form falls back to none.
            ->assertInertia(fn (Assert $page) => $page->missing('metadataSuggestions'));
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
