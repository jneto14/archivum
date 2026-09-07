<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\SuggestMetadataVocabulary;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MetadataVocabularyTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_ranks_keys_by_how_often_the_workspace_files_them()
    {
        $workspace = Workspace::factory()->create();

        Document::factory()->count(3)->for($workspace)->create(['metadata' => ['Fornecedor' => 'EDP']]);
        Document::factory()->for($workspace)->create(['metadata' => ['Apólice' => 'AP-1']]);

        $vocabulary = app(SuggestMetadataVocabulary::class)->handle($workspace);

        $this->assertSame(['Fornecedor', 'Apólice'], array_column($vocabulary, 'key'));
    }

    public function test_it_offers_one_spelling_per_field_and_picks_the_most_used_one()
    {
        $workspace = Workspace::factory()->create();

        Document::factory()->count(2)->for($workspace)->create(['metadata' => ['NIF' => '501234567']]);
        Document::factory()->for($workspace)->create(['metadata' => ['nif' => '502345678']]);
        Document::factory()->for($workspace)->create(['metadata' => ['Contribuinte' => '503456789']]);

        $vocabulary = app(SuggestMetadataVocabulary::class)->handle($workspace);

        // One entry, not three: the drift is what this is meant to stop, so
        // offering every spelling of it would be handing the drift back.
        // `Contribuinte` collapses in because it is a shipped alias of the
        // same kind, not merely a different casing — see IntakeVocabulary.
        $this->assertSame(['NIF'], array_column($vocabulary, 'key'));
        $this->assertEqualsCanonicalizing(
            ['501234567', '502345678', '503456789'],
            $vocabulary[0]['values'],
        );
    }

    public function test_it_ranks_values_by_how_often_they_are_filed()
    {
        $workspace = Workspace::factory()->create();

        Document::factory()->count(2)->for($workspace)->create(['metadata' => ['Fornecedor' => 'EDP']]);
        Document::factory()->for($workspace)->create(['metadata' => ['Fornecedor' => 'Galp']]);

        $vocabulary = app(SuggestMetadataVocabulary::class)->handle($workspace);

        $this->assertSame(['EDP', 'Galp'], $vocabulary[0]['values']);
    }

    public function test_it_records_which_document_types_a_key_appears_on()
    {
        $workspace = Workspace::factory()->create();
        $invoice = DocumentType::factory()->for($workspace)->create();
        $contract = DocumentType::factory()->for($workspace)->create();

        Document::factory()->for($workspace)->for($invoice, 'documentType')->create(['metadata' => ['Fornecedor' => 'EDP']]);
        Document::factory()->for($workspace)->for($contract, 'documentType')->create(['metadata' => ['Contraparte' => 'Acme']]);

        $vocabulary = app(SuggestMetadataVocabulary::class)->handle($workspace);
        $keys = array_column($vocabulary, 'documentTypeIds', 'key');

        $this->assertSame([$invoice->id], $keys['Fornecedor']);
        $this->assertSame([$contract->id], $keys['Contraparte']);
    }

    public function test_it_ignores_blank_values_and_another_workspace_entirely()
    {
        $workspace = Workspace::factory()->create();
        $other = Workspace::factory()->create();

        Document::factory()->for($workspace)->create(['metadata' => ['Fornecedor' => 'EDP', 'Vazio' => '']]);
        Document::factory()->for($other)->create(['metadata' => ['Segredo' => 'Alheio']]);

        $vocabulary = app(SuggestMetadataVocabulary::class)->handle($workspace);

        $this->assertSame(['Fornecedor'], array_column($vocabulary, 'key'));
        $this->assertSame(['EDP'], $vocabulary[0]['values']);
    }

    public function test_the_create_form_carries_the_workspace_vocabulary()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        Document::factory()->for($workspace)->create(['metadata' => ['Fornecedor' => 'EDP']]);

        $this->actingAs($member->user)
            ->get(route('documents.create', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metadataVocabulary.0.key', 'Fornecedor')
                ->where('metadataVocabulary.0.values', ['EDP']),
            );
    }

    public function test_the_edit_form_carries_the_workspace_vocabulary()
    {
        $workspace = Workspace::factory()->create();
        $creator = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $document = Document::factory()->for($workspace)->create([
            'created_by' => $creator->user->id,
            'metadata' => ['Fornecedor' => 'EDP'],
        ]);

        $this->actingAs($creator->user)
            ->get(route('documents.edit', $document))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('metadataVocabulary.0.key', 'Fornecedor'),
            );
    }
}
