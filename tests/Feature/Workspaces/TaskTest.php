<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentLocation;
use App\Models\OrganizationLevel;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use App\Models\Tag;
use App\Models\Task;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Notifications\DocumentExportReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TaskTest extends TestCase
{
    use RefreshDatabase;

    public function test_workspace_admin_can_view_the_tasks_page()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        Task::factory()->for($workspace)->for($admin->user)->completed()->create();

        $response = $this->actingAs($admin->user)->get(route('workspaces.tasks.index', $workspace));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('tasks.0.type', TaskType::DocumentExport->value)
            ->where('tasks.0.status', TaskStatus::Completed->value)
            ->where('tasks.0.triggered_by', $admin->user->name),
        );
    }

    public function test_non_admin_member_cannot_view_the_tasks_page()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($member->user)->get(route('workspaces.tasks.index', $workspace));

        $response->assertForbidden();
    }

    public function test_workspace_admin_can_start_a_document_export()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        Document::factory()->for($workspace)->count(2)->create();

        $response = $this->actingAs($admin->user)->post(route('workspaces.tasks.store', $workspace));

        $response->assertRedirect();

        $task = Task::query()->where('workspace_id', $workspace->id)->sole();
        $this->assertSame(TaskStatus::Completed, $task->status);
        $this->assertSame(2, $task->result['documents_count']);
        Storage::disk('local')->assertExists($task->result['path']);
    }

    public function test_a_completed_exports_csv_includes_tags_and_the_current_location()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = OrganizationScheme::factory()->for($workspace)->create();
        $level = OrganizationLevel::factory()->for($scheme, 'scheme')->create();
        $node = OrganizationNode::factory()->for($level, 'level')->create(['value' => 'A']);
        $document = Document::factory()->for($workspace)->create(['title' => 'Invoice #1']);
        $document->tags()->attach(Tag::factory()->for($workspace)->create(['name' => 'Urgent']));
        DocumentLocation::factory()->for($document)->for($node, 'node')->create();

        $response = $this->actingAs($admin->user)->post(route('workspaces.tasks.store', $workspace));

        $response->assertRedirect();

        $task = Task::query()->where('workspace_id', $workspace->id)->sole();
        $csv = Storage::disk('local')->get($task->result['path']);

        $this->assertStringContainsString('Tags', $csv);
        $this->assertStringContainsString('Location', $csv);
        $this->assertStringContainsString('Urgent', $csv);
        $this->assertStringContainsString('A', $csv);
    }

    public function test_completing_an_export_notifies_the_triggering_user()
    {
        Storage::fake('local');
        Notification::fake();

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->post(route('workspaces.tasks.store', $workspace));

        $response->assertRedirect();

        Task::query()->where('workspace_id', $workspace->id)->sole();

        Notification::assertSentTo($admin->user, DocumentExportReady::class);
    }

    public function test_a_signed_download_link_lets_a_workspace_admin_download_the_result()
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/example.csv', "Title\nInvoice");

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $task = Task::factory()->for($workspace)->for($admin->user)->completed()->create([
            'result' => ['disk' => 'local', 'path' => 'exports/example.csv'],
        ]);

        $url = URL::temporarySignedRoute(
            'workspaces.tasks.download.signed',
            now()->addDay(),
            ['workspace' => $workspace->id, 'task' => $task->id],
        );

        $response = $this->actingAs($admin->user)->get($url);

        $response->assertOk();
    }

    public function test_a_signed_download_link_rejects_an_invalid_signature()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $task = Task::factory()->for($workspace)->for($admin->user)->completed()->create();

        $response = $this->actingAs($admin->user)->get(route('workspaces.tasks.download.signed', [$workspace, $task]));

        $response->assertForbidden();
    }

    public function test_a_signed_download_link_rejects_a_user_who_is_no_longer_a_workspace_admin()
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/example.csv', "Title\nInvoice");

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $task = Task::factory()->for($workspace)->for($member->user)->completed()->create([
            'result' => ['disk' => 'local', 'path' => 'exports/example.csv'],
        ]);

        $url = URL::temporarySignedRoute(
            'workspaces.tasks.download.signed',
            now()->addDay(),
            ['workspace' => $workspace->id, 'task' => $task->id],
        );

        $response = $this->actingAs($member->user)->get($url);

        $response->assertForbidden();
    }

    public function test_starting_an_export_while_one_is_already_running_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $lock = Cache::lock(TaskType::DocumentExport->lockKey($workspace->id), 600);
        $lock->get();

        $response = $this->actingAs($admin->user)->post(route('workspaces.tasks.store', $workspace));

        $response->assertSessionHasErrors('task');
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_non_admin_member_cannot_start_an_export()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($member->user)->post(route('workspaces.tasks.store', $workspace));

        $response->assertForbidden();
        $this->assertDatabaseCount('tasks', 0);
    }

    public function test_workspace_admin_can_retry_a_failed_task()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $task = Task::factory()->for($workspace)->for($admin->user)->failed()->create();

        $response = $this->actingAs($admin->user)->post(route('workspaces.tasks.retry', [$workspace, $task]));

        $response->assertRedirect();
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_retrying_a_non_failed_task_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $task = Task::factory()->for($workspace)->for($admin->user)->completed()->create();

        $response = $this->actingAs($admin->user)->post(route('workspaces.tasks.retry', [$workspace, $task]));

        $response->assertSessionHasErrors('task');
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_non_admin_member_cannot_retry_a_task()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $task = Task::factory()->for($workspace)->for($member->user)->failed()->create();

        $response = $this->actingAs($member->user)->post(route('workspaces.tasks.retry', [$workspace, $task]));

        $response->assertForbidden();
    }

    public function test_retrying_a_task_from_a_different_workspace_returns_not_found()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $otherWorkspace = Workspace::factory()->create();
        $task = Task::factory()->for($otherWorkspace)->failed()->create();

        $response = $this->actingAs($admin->user)->post(route('workspaces.tasks.retry', [$workspace, $task]));

        $response->assertNotFound();
    }

    public function test_workspace_admin_can_download_a_completed_tasks_result()
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/example.csv', "Title\nInvoice");

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $task = Task::factory()->for($workspace)->for($admin->user)->completed()->create([
            'result' => ['disk' => 'local', 'path' => 'exports/example.csv'],
        ]);

        $response = $this->actingAs($admin->user)->get(route('workspaces.tasks.download', [$workspace, $task]));

        $response->assertOk();
    }

    public function test_downloading_an_incomplete_tasks_result_returns_not_found()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $task = Task::factory()->for($workspace)->for($admin->user)->create();

        $response = $this->actingAs($admin->user)->get(route('workspaces.tasks.download', [$workspace, $task]));

        $response->assertNotFound();
    }

    public function test_non_admin_member_cannot_download_a_tasks_result()
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/example.csv', "Title\nInvoice");

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $task = Task::factory()->for($workspace)->for($member->user)->completed()->create([
            'result' => ['disk' => 'local', 'path' => 'exports/example.csv'],
        ]);

        $response = $this->actingAs($member->user)->get(route('workspaces.tasks.download', [$workspace, $task]));

        $response->assertForbidden();
    }

    public function test_a_bulk_document_move_task_shows_up_in_the_tasks_list()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        Task::factory()->for($workspace)->for($admin->user)->completed()->create([
            'type' => TaskType::BulkDocumentMove,
        ]);

        $response = $this->actingAs($admin->user)->get(route('workspaces.tasks.index', $workspace));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('tasks.0.type', TaskType::BulkDocumentMove->value),
        );
    }

    public function test_workspace_admin_can_retry_a_failed_bulk_document_move_task()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = OrganizationScheme::factory()->for($workspace)->create();
        $level = OrganizationLevel::factory()->for($scheme, 'scheme')->create();
        $source = OrganizationNode::factory()->for($level, 'level')->create();
        $target = OrganizationNode::factory()->for($level, 'level')->create();
        $task = Task::factory()->for($workspace)->for($admin->user)->failed()->create([
            'type' => TaskType::BulkDocumentMove,
            'payload' => ['source_node_id' => $source->id, 'target_node_id' => $target->id],
        ]);

        $response = $this->actingAs($admin->user)->post(route('workspaces.tasks.retry', [$workspace, $task]));

        $response->assertRedirect();
        $this->assertSame(TaskStatus::Completed, $task->fresh()->status);
    }

    public function test_downloading_a_bulk_document_move_tasks_result_returns_not_found()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $task = Task::factory()->for($workspace)->for($admin->user)->completed()->create([
            'type' => TaskType::BulkDocumentMove,
        ]);

        $response = $this->actingAs($admin->user)->get(route('workspaces.tasks.download', [$workspace, $task]));

        $response->assertNotFound();
    }
}
