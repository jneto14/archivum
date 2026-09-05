<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Actions\Organization\MigrateNodeDocuments;
use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\WorkspaceRole;
use App\Jobs\BulkMoveDocuments;
use App\Jobs\ExportWorkspaceDocuments;
use App\Models\Document;
use App\Models\DocumentLocation;
use App\Models\OrganizationLevel;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use App\Models\Tag;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Notifications\DocumentExportReady;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Support\CauserResolver;
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
            ->where('tasks.data.0.type', TaskType::DocumentExport->value)
            ->where('tasks.data.0.status', TaskStatus::Completed->value)
            ->where('tasks.data.0.triggered_by', $admin->user->name),
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

    public function test_the_export_ready_email_is_sent_in_the_triggering_users_locale()
    {
        Storage::fake('local');
        Notification::fake();

        $workspace = Workspace::factory()->create();
        $user = User::factory()->create(['locale' => 'pt']);
        $admin = WorkspaceUser::factory()->for($workspace)->for($user)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->post(route('workspaces.tasks.store', $workspace));

        $response->assertRedirect();

        Notification::assertSentTo(
            $admin->user,
            DocumentExportReady::class,
            fn ($notification, $channels, $notifiable, $locale) => $locale === 'pt',
        );
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
            ->where('tasks.data.0.type', TaskType::BulkDocumentMove->value),
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

    public function test_retrying_an_export_while_another_is_already_running_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $task = Task::factory()->for($workspace)->for($admin->user)->failed()->create();

        Cache::lock(TaskType::DocumentExport->lockKey($workspace->id), 600)->get();

        $response = $this->actingAs($admin->user)->post(route('workspaces.tasks.retry', [$workspace, $task]));

        $response->assertSessionHasErrors('task');
        $this->assertSame(
            TaskStatus::Failed,
            $task->fresh()->status,
            'A refused retry must leave the task failed, not queued behind a lock it never got.',
        );
    }

    public function test_retrying_a_bulk_move_while_another_is_already_running_is_rejected()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $task = Task::factory()->for($workspace)->for($admin->user)->failed()->create([
            'type' => TaskType::BulkDocumentMove,
        ]);

        Cache::lock(TaskType::BulkDocumentMove->lockKey($workspace->id), 600)->get();

        $response = $this->actingAs($admin->user)->post(route('workspaces.tasks.retry', [$workspace, $task]));

        $response->assertSessionHasErrors('task');
        $this->assertSame(TaskStatus::Failed, $task->fresh()->status);
    }

    public function test_a_failing_export_records_the_error_and_releases_its_lock()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $task = Task::factory()->for($workspace)->for($admin->user)->create();

        // A disk name nothing is configured for: the shape a misconfigured
        // installation has, and it throws exactly where the export writes.
        config()->set('archivum.attachments.disk', 'no-such-disk');

        $lock = Cache::lock(TaskType::DocumentExport->lockKey($workspace->id), 600);
        $this->assertTrue($lock->get());

        (new ExportWorkspaceDocuments($task, (string) $lock->owner()))->handle();

        $task->refresh();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertNotEmpty($task->result['error']);

        $this->assertTrue(
            Cache::lock(TaskType::DocumentExport->lockKey($workspace->id), 600)->get(),
            'A failed export must release its lock, or the workspace can never export again.',
        );
    }

    public function test_a_failing_bulk_move_records_the_error_and_releases_its_lock()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $scheme = OrganizationScheme::factory()->for($workspace)->create();
        $level = OrganizationLevel::factory()->for($scheme, 'scheme')->create();
        $node = OrganizationNode::factory()->for($level, 'level')->create();
        $task = Task::factory()->for($workspace)->for($admin->user)->create([
            'type' => TaskType::BulkDocumentMove,
        ]);

        $lock = Cache::lock(TaskType::BulkDocumentMove->lockKey($workspace->id), 600);
        $this->assertTrue($lock->get());

        // Moving a node onto itself is what MigrateNodeDocuments refuses, and
        // is the shortest way to make the job's own work throw.
        (new BulkMoveDocuments($task, $node, $node, (string) $lock->owner()))
            ->handle(app(MigrateNodeDocuments::class), app(CauserResolver::class));

        $task->refresh();

        $this->assertSame(TaskStatus::Failed, $task->status);
        $this->assertNotEmpty($task->result['error']);

        $this->assertTrue(
            Cache::lock(TaskType::BulkDocumentMove->lockKey($workspace->id), 600)->get(),
            'A failed move must release its lock, or the workspace can never bulk-move again.',
        );
    }

    /**
     * By who triggered it, which is a name on `users` rather than a column on
     * the task.
     */
    public function test_tasks_can_be_ordered_by_who_triggered_them()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $admin->user->update(['name' => 'Zoe']);
        $other = User::factory()->create(['name' => 'Ana']);

        Task::factory()->for($workspace)->for($admin->user)->completed()->create();
        Task::factory()->for($workspace)->for($other)->completed()->create();

        $this->actingAs($admin->user)
            ->get(route('workspaces.tasks.index', [
                'workspace' => $workspace,
                'sort' => 'triggered_by',
                'direction' => 'asc',
            ]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('sort.key', 'triggered_by')
                ->where('tasks.data.0.triggered_by', 'Ana')
                ->where('tasks.data.1.triggered_by', 'Zoe'),
            );
    }
}
