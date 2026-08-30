<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class ActivityTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_view_the_activity_feed()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        Document::factory()->for($workspace)->create(['title' => 'Invoice #1']);

        $response = $this->actingAs($admin->user)->get(route('workspaces.activity.index', $workspace));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('activities.data.0.log_name', 'document')
            ->where('activities.data.0.event', 'created')
            ->where('activities.data.0.label', 'Invoice #1'),
        );
    }

    public function test_non_admin_member_cannot_view_the_activity_feed()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($member->user)->get(route('workspaces.activity.index', $workspace));

        $response->assertForbidden();
    }

    public function test_activity_from_another_workspace_never_appears_in_this_workspaces_feed()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $otherWorkspace = Workspace::factory()->create();
        $secretDocument = Document::factory()->for($otherWorkspace)->create(['title' => 'Secret invoice']);

        $response = $this->actingAs($admin->user)->get(route('workspaces.activity.index', $workspace));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where(
                'activities.data',
                fn (Collection $activities) => !$activities->pluck('label')->contains('Secret invoice'),
            ),
        );

        $this->assertSame(
            0,
            Activity::query()->forSubject($secretDocument)->where('workspace_id', $workspace->id)->count(),
        );
    }

    public function test_creating_a_document_logs_activity_scoped_to_its_workspace()
    {
        $workspace = Workspace::factory()->create();
        $document = Document::factory()->for($workspace)->create(['title' => 'Invoice #1']);

        $activity = Activity::query()->forSubject($document)->sole();

        $this->assertSame($workspace->id, $activity->workspace_id);
        $this->assertSame('document', $activity->log_name);
        $this->assertSame('created', $activity->event);
        $this->assertSame('Invoice #1', $activity->getProperty('label'));
    }

    public function test_updating_a_documents_tracked_fields_logs_an_update_activity()
    {
        $document = Document::factory()->create(['title' => 'Draft']);

        $document->update(['title' => 'Final']);

        $activity = Activity::query()->forSubject($document)->forEvent('updated')->sole();

        $this->assertSame('Final', $activity->getProperty('label'));
        $this->assertSame('Final', $activity->attribute_changes['attributes']['title'] ?? null);
    }

    public function test_untracked_field_changes_do_not_log_an_update_activity()
    {
        $document = Document::factory()->create(['metadata' => ['foo' => 'bar']]);

        $document->update(['metadata' => ['foo' => 'baz']]);

        $this->assertSame(0, Activity::query()->forSubject($document)->forEvent('updated')->count());
    }

    public function test_workspace_membership_changes_are_logged()
    {
        $workspace = Workspace::factory()->create();
        $membership = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $activity = Activity::query()->forSubject($membership)->sole();

        $this->assertSame($workspace->id, $activity->workspace_id);
        $this->assertSame('workspace_member', $activity->log_name);
        $this->assertSame($membership->user->name, $activity->getProperty('label'));
    }
}
