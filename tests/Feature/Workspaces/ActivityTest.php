<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Actions\Documents\MoveDocument;
use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Tag;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
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

    public function test_creating_a_tag_is_logged()
    {
        $workspace = Workspace::factory()->create();
        $tag = Tag::factory()->for($workspace)->create(['name' => 'Urgent']);

        $activity = Activity::query()->forSubject($tag)->sole();

        $this->assertSame($workspace->id, $activity->workspace_id);
        $this->assertSame('tag', $activity->log_name);
        $this->assertSame('Urgent', $activity->getProperty('label'));
    }

    public function test_uploading_an_attachment_is_logged()
    {
        $document = Document::factory()->create();
        $attachment = DocumentAttachment::factory()->for($document)->create(['filename' => 'scan.pdf']);

        $activity = Activity::query()->forSubject($attachment)->sole();

        $this->assertSame($document->workspace_id, $activity->workspace_id);
        $this->assertSame('document_attachment', $activity->log_name);
        $this->assertSame('created', $activity->event);
        $this->assertSame('scan.pdf', $activity->getProperty('label'));
    }

    public function test_deleting_an_attachment_is_logged_but_not_updating_one()
    {
        $attachment = DocumentAttachment::factory()->create();

        $attachment->delete();

        $this->assertSame(0, Activity::query()->forSubject($attachment)->forEvent('updated')->count());
        $this->assertSame(1, Activity::query()->forSubject($attachment)->forEvent('deleted')->count());
    }

    public function test_moving_a_document_logs_activity_with_its_new_location()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $document = Document::factory()->for($workspace)->create(['title' => 'Invoice #1']);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Scheme', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $node = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $this->actingAs($admin->user);
        app(MoveDocument::class)->handle($document, $node);

        $activity = Activity::query()->where('log_name', 'document_location')->sole();

        $this->assertSame($workspace->id, $activity->workspace_id);
        $this->assertSame($admin->user->id, $activity->causer_id);
        $this->assertSame("Invoice #1 → {$node->path()}", $activity->getProperty('label'));
    }

    public function test_a_bulk_document_move_attributes_its_activity_to_the_triggering_user()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = app(CreateScheme::class)->handle($workspace, 'Scheme', [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
        $source = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');
        $target = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '002');
        $document = Document::factory()->for($workspace)->create();
        app(MoveDocument::class)->handle($document, $source);

        $this->actingAs($admin->user)->post(route('organization.nodes.migrate', $source), [
            'target_node_id' => $target->id,
        ])->assertRedirect();

        $activity = Activity::query()->where('log_name', 'document_location')->latest('id')->first();

        $this->assertSame($admin->user->id, $activity->causer_id);
    }
}
