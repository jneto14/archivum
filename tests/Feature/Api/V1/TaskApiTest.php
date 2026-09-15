<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\TaskStatus;
use App\Enums\TaskType;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Spatie\Activitylog\Models\Activity;
use Tests\TestCase;

class TaskApiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($this->workspace)->create(['role' => WorkspaceRole::Admin]);
        $this->admin = $member->user;
        $this->token = $this->admin->createToken('CLI')->plainTextToken;
    }

    /**
     * The contract for anything slow: 202 and the task, which is then read
     * back until it finishes.
     */
    public function test_starting_an_export_answers_with_the_queued_task()
    {
        Queue::fake();
        $type = DocumentType::factory()->for($this->workspace)->create();
        Document::factory()->for($this->workspace)->for($type)->create();

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/tasks");

        $response->assertStatus(202)
            ->assertJsonPath('data.type', TaskType::DocumentExport->value)
            ->assertJsonPath('data.triggered_by.id', $this->admin->id);

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/tasks/{$response->json('data.id')}")
            ->assertOk()
            ->assertJsonPath('data.id', $response->json('data.id'));
    }

    public function test_tasks_can_be_filtered_by_status_and_type()
    {
        Task::factory()->for($this->workspace)->for($this->admin, 'user')->create([
            'type' => TaskType::DocumentExport,
            'status' => TaskStatus::Completed,
        ]);
        Task::factory()->for($this->workspace)->for($this->admin, 'user')->create([
            'type' => TaskType::AttachmentTextExtraction,
            'status' => TaskStatus::Failed,
        ]);

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/tasks?status=" . TaskStatus::Failed->value)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', TaskStatus::Failed->value);

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/tasks?type=" . TaskType::DocumentExport->value)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * `result` holds the disk and path an export was written to, which is this
     * application's business. A client is told the file is there, not where.
     */
    public function test_a_finished_export_says_a_file_is_available_without_saying_where()
    {
        $task = Task::factory()->for($this->workspace)->for($this->admin, 'user')->create([
            'type' => TaskType::DocumentExport,
            'status' => TaskStatus::Completed,
            'result' => ['disk' => 'local', 'path' => 'exports/secret.zip'],
        ]);

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/tasks/{$task->id}");

        $response->assertOk()->assertJsonPath('data.result_available', true);
        $this->assertStringNotContainsString('exports/secret.zip', $response->getContent());
    }

    public function test_a_failure_reports_its_message()
    {
        $task = Task::factory()->for($this->workspace)->for($this->admin, 'user')->create([
            'type' => TaskType::DocumentExport,
            'status' => TaskStatus::Failed,
            'result' => ['error' => 'Disk full'],
        ]);

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/tasks/{$task->id}")
            ->assertOk()
            ->assertJsonPath('data.error', 'Disk full')
            ->assertJsonPath('data.result_available', false);
    }

    public function test_an_export_that_has_no_file_is_not_found_rather_than_explained()
    {
        Storage::fake('local');
        $task = Task::factory()->for($this->workspace)->for($this->admin, 'user')->create([
            'type' => TaskType::DocumentExport,
            'status' => TaskStatus::Completed,
            'result' => ['disk' => 'local', 'path' => 'exports/gone.zip'],
        ]);

        $this->withToken($this->token)
            ->get("/api/v1/workspaces/{$this->workspace->id}/tasks/{$task->id}/download")
            ->assertNotFound();
    }

    public function test_a_task_from_another_workspace_is_not_found()
    {
        $stranger = Workspace::factory()->create();
        $theirs = Task::factory()->for($stranger)->for($this->admin, 'user')->create();

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/tasks/{$theirs->id}")
            ->assertNotFound();
    }

    public function test_the_activity_trail_is_readable_and_filterable()
    {
        $type = DocumentType::factory()->for($this->workspace)->create();
        Document::factory()->for($this->workspace)->for($type)->create();

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/activity");

        $response->assertOk()->assertJsonStructure([
            'data' => [['id', 'log_name', 'event', 'label', 'created_at']],
        ]);

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/activity?event=nothing-matches-this")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_a_stranger_cannot_read_a_workspace_s_tasks_or_trail()
    {
        $stranger = Workspace::factory()->create();

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$stranger->id}/tasks")
            ->assertForbidden();

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$stranger->id}/activity")
            ->assertForbidden();
    }

    /**
     * `paginate()` runs a fresh query per page with a different OFFSET, so an
     * order that leaves ties lets the database settle them differently each
     * time: a client walking the list is handed one task twice and never sees
     * the one it displaced. Reading a batch of uploads creates exactly this —
     * a run of tasks stamped the same second.
     */
    public function test_tasks_stamped_the_same_second_are_ordered_by_id()
    {
        Task::factory()->count(4)->for($this->workspace)->for($this->admin, 'user')
            ->create(['created_at' => '2026-09-15 12:00:00']);

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/tasks")
            ->assertOk();

        $this->assertSame(
            Task::query()->orderByDesc('id')->pluck('id')->all(),
            array_column($response->json('data'), 'id'),
        );
    }

    /**
     * One action writes several entries at the same instant, so the trail is
     * the listing most likely to tie.
     */
    public function test_activity_stamped_the_same_second_is_ordered_by_id()
    {
        $type = DocumentType::factory()->for($this->workspace)->create();
        Document::factory()->count(4)->for($this->workspace)->for($type)->create();
        Activity::query()->update(['created_at' => '2026-09-15 12:00:00']);

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/activity")
            ->assertOk();

        $this->assertSame(
            Activity::query()->orderByDesc('id')->pluck('id')->all(),
            array_column($response->json('data'), 'id'),
        );
    }
}
