<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\IntakeLabelStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\RereadWorkspaceSuggestions;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\IntakeLabel;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Who may answer a learned label, and what an answer does.
 *
 * The rule underneath all of it: nothing the archive taught itself is read by
 * anything until a workspace admin says so.
 */
class IntakeLabelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Settings holds the standing list and the way off it. The unanswered ones
     * are a queue of work and live on the review page, which is the only screen
     * with a badge pointing at it.
     */
    public function test_the_settings_page_lists_the_labels_in_use_and_nothing_else()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        IntakeLabel::factory()->accepted()->for($workspace)->create([
            'kind' => 'tax_id',
            'label' => 'contribuinte',
        ]);

        IntakeLabel::factory()->for($workspace)->create(['label' => 'steuernummer', 'support' => 7]);
        IntakeLabel::factory()->rejected()->for($workspace)->create(['label' => 'de']);

        $this->actingAs($admin->user)
            ->get(route('workspaces.settings.show', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('intakeLabels', 1)
                ->where('intakeLabels.0.label', 'contribuinte')
                // Named in the interface language rather than shown as the
                // normalised key, which is machinery.
                ->where('intakeLabels.0.field', 'Tax number'),
            );
    }

    /**
     * A learned kind has no name the application ships, so it is shown as the
     * field this workspace actually spells it — the whole point of the kinds
     * not being a list in the code.
     */
    public function test_a_learned_field_is_named_the_way_the_workspace_writes_it()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        IntakeLabel::factory()->accepted()->for($workspace)->create([
            'kind' => 'no_apolice',
            'label' => 'seguro',
        ]);

        $this->documentFiling($workspace, ['Nº Apólice' => 'AP4471182']);

        $this->actingAs($admin->user)
            ->get(route('workspaces.settings.show', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('intakeLabels.0.field', 'Nº Apólice'));
    }

    public function test_an_admin_can_adopt_a_candidate()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $label = IntakeLabel::factory()->for($workspace)->create();

        $this->actingAs($admin->user)
            ->patch(route('workspaces.intake-labels.update', [$workspace, $label]), ['status' => 'accepted'])
            ->assertRedirect();

        $this->assertSame(IntakeLabelStatus::Accepted, $label->refresh()->status);
    }

    /**
     * The half that was missing. Every document already in the archive stored
     * what its text was found to contain back when this word meant nothing, and
     * without a re-read the new label would only ever apply to documents filed
     * after it — which is the opposite of why anybody accepts one.
     */
    public function test_answering_a_label_has_the_archive_read_again()
    {
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $label = IntakeLabel::factory()->for($workspace)->create();

        $this->actingAs($admin->user)
            ->patch(route('workspaces.intake-labels.update', [$workspace, $label]), ['status' => 'accepted']);

        Queue::assertPushed(
            RereadWorkspaceSuggestions::class,
            fn (RereadWorkspaceSuggestions $job): bool => $job->workspace->is($workspace),
        );
    }

    /**
     * Retiring an accepted label is the same write as turning one down, and
     * deliberately so: deleting the row would stop the reader using it and let
     * the next mining run offer it straight back.
     */
    public function test_retiring_an_accepted_label_records_a_no_rather_than_forgetting_it()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $label = IntakeLabel::factory()->accepted()->for($workspace)->create();

        $this->actingAs($admin->user)
            ->patch(route('workspaces.intake-labels.update', [$workspace, $label]), ['status' => 'rejected'])
            ->assertRedirect();

        $this->assertSame(IntakeLabelStatus::Rejected, $label->refresh()->status);
        $this->assertDatabaseHas('intake_labels', ['id' => $label->id]);
    }

    /**
     * Nothing puts a candidate back to unanswered, so no request may ask for it.
     */
    public function test_a_label_cannot_be_put_back_to_unanswered()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $label = IntakeLabel::factory()->accepted()->for($workspace)->create();

        $this->actingAs($admin->user)
            ->patch(route('workspaces.intake-labels.update', [$workspace, $label]), ['status' => 'pending'])
            ->assertSessionHasErrors('status');

        $this->assertSame(IntakeLabelStatus::Accepted, $label->refresh()->status);
    }

    /**
     * A label changes how every document in the workspace is read, which is an
     * admin's decision rather than a member's.
     */
    public function test_a_member_cannot_answer_a_label()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $label = IntakeLabel::factory()->for($workspace)->create();

        $this->actingAs($member->user)
            ->patch(route('workspaces.intake-labels.update', [$workspace, $label]), ['status' => 'accepted'])
            ->assertForbidden();

        $this->assertSame(IntakeLabelStatus::Pending, $label->refresh()->status);
    }

    public function test_a_label_cannot_be_answered_through_another_workspace()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $foreign = IntakeLabel::factory()->create();

        $this->actingAs($admin->user)
            ->patch(route('workspaces.intake-labels.update', [$workspace, $foreign]), ['status' => 'accepted'])
            ->assertNotFound();

        $this->assertSame(IntakeLabelStatus::Pending, $foreign->refresh()->status);
    }

    /**
     * Create a document carrying $metadata, so the workspace has a spelling for
     * whichever keys it uses.
     *
     * @param Workspace $workspace The owning workspace.
     * @param array<string, string> $metadata What somebody filled in on it.
     *
     * @return void No return value; persists the document as a side effect.
     */
    private function documentFiling(Workspace $workspace, array $metadata): void
    {
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        Document::factory()->for($workspace)->create([
            'document_type_id' => DocumentType::factory()->for($workspace)->create()->id,
            'created_by' => $member->user_id,
            'metadata' => $metadata,
        ]);
    }
}
