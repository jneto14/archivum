<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\UploadAttachment;
use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceLimit;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class WorkspaceUsageTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_workspace_usage()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $admin->user, $type, 'Invoice', null, null);
        app(UploadAttachment::class)->handle($document, UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf'), $admin->user);
        WorkspaceLimit::factory()->for($workspace)->create(['documents' => 100, 'users' => null]);

        $response = $this->actingAs($admin->user)->get(route('workspaces.usage', $workspace));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('usage.documents.used', 1)
            ->where('usage.documents.limit', 100)
            ->where('usage.attachments.used', 1)
            ->where('usage.users.used', 1)
            ->where('usage.users.limit', null)
            ->where('usage.storage.limit', null)
            ->where('usage.storage.used', fn (int $used) => $used > 0),
        );
    }

    public function test_workspace_without_configured_limits_reports_null_limits()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->get(route('workspaces.usage', $workspace));

        $response->assertOk();
        $response->assertInertia(fn (Assert $page) => $page
            ->where('usage.storage.limit', null)
            ->where('usage.users.limit', null)
            ->where('usage.documents.limit', null)
            ->where('usage.attachments.limit', null),
        );
    }

    public function test_non_admin_member_cannot_view_workspace_usage()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($member->user)->get(route('workspaces.usage', $workspace));

        $response->assertForbidden();
    }

    public function test_non_member_cannot_view_workspace_usage()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($outsider->user)->get(route('workspaces.usage', $workspace));

        $response->assertForbidden();
    }

    /**
     * The storage total is the only usage figure that reads a column rather
     * than counting rows, so it is the only one that can be made to touch disk
     * per attachment. `document_attachments (document_id, size)` is what keeps
     * it index-only; measured on 5,000 attachments in the workspace, losing
     * that index costs 11.6ms against 1.6ms, and it gets worse with volume.
     *
     * Asserted through EXPLAIN rather than a timing, because a timing that
     * fails on a loaded CI box teaches people to ignore it.
     */
    public function test_the_storage_total_is_answered_from_an_index_without_reading_rows()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $admin->user, $type, 'Invoice', null, null);
        app(UploadAttachment::class)->handle($document, UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf'), $admin->user);

        DB::flushQueryLog();
        DB::enableQueryLog();
        app(CalculateWorkspaceUsage::class)->storageBytes($workspace);
        $log = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(1, $log, 'The storage total must still be a single query.');

        $plan = DB::select('EXPLAIN ' . $log[0]['query'], $log[0]['bindings']);
        $attachments = collect($plan)->firstWhere('table', 'document_attachments');

        $this->assertNotNull($attachments, 'EXPLAIN did not mention document_attachments.');
        $this->assertSame(
            'document_attachments_document_id_size_index',
            $attachments->key,
            'The sum stopped using the covering index — it is reading attachment rows off disk again.',
        );
        $this->assertStringContainsString(
            'Using index',
            (string) $attachments->Extra,
            'The sum is no longer index-only, so it reads every matching attachment row.',
        );
    }
}
