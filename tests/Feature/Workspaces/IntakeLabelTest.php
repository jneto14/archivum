<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\IntakeLabelStatus;
use App\Enums\WorkspaceRole;
use App\Models\IntakeLabel;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_an_admin_is_shown_the_candidates_and_the_labels_in_use()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        IntakeLabel::factory()->for($workspace)->create(['label' => 'steuernummer', 'support' => 7]);
        IntakeLabel::factory()->accepted()->for($workspace)->create(['label' => 'contribuinte']);

        // Rejected ones are recorded so mining stops asking, not so anybody
        // has to read them again.
        IntakeLabel::factory()->rejected()->for($workspace)->create(['label' => 'de']);

        $this->actingAs($admin->user)
            ->get(route('workspaces.settings.show', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('intakeLabels.pending', 1)
                ->where('intakeLabels.pending.0.label', 'steuernummer')
                ->where('intakeLabels.pending.0.support', 7)
                ->has('intakeLabels.accepted', 1)
                ->where('intakeLabels.accepted.0.label', 'contribuinte'),
            );
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
}
