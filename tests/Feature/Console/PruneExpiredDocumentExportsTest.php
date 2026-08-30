<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\TaskType;
use App\Models\Task;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneExpiredDocumentExportsTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_deletes_export_files_past_their_retention_window()
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/old.csv', 'Title');

        $workspace = Workspace::factory()->create();
        $task = Task::factory()->for($workspace)->completed()->create([
            'result' => ['disk' => 'local', 'path' => 'exports/old.csv'],
            'finished_at' => now()->subDays(8),
        ]);

        $this->artisan('exports:prune')->assertSuccessful();

        Storage::disk('local')->assertMissing('exports/old.csv');
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);
    }

    public function test_it_keeps_export_files_still_within_their_retention_window()
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/recent.csv', 'Title');

        $workspace = Workspace::factory()->create();
        Task::factory()->for($workspace)->completed()->create([
            'result' => ['disk' => 'local', 'path' => 'exports/recent.csv'],
            'finished_at' => now()->subDay(),
        ]);

        $this->artisan('exports:prune')->assertSuccessful();

        Storage::disk('local')->assertExists('exports/recent.csv');
    }

    public function test_it_ignores_bulk_document_move_tasks()
    {
        Storage::fake('local');
        Storage::disk('local')->put('exports/moved.csv', 'Title');

        $workspace = Workspace::factory()->create();
        Task::factory()->for($workspace)->completed()->create([
            'type' => TaskType::BulkDocumentMove,
            'result' => ['source_node_id' => 'x', 'target_node_id' => 'y'],
            'finished_at' => now()->subDays(8),
        ]);

        $this->artisan('exports:prune')->assertSuccessful();

        Storage::disk('local')->assertExists('exports/moved.csv');
    }
}
