<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\UploadAttachment;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceLimit;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

        $response = $this->actingAs($admin->user)->getJson(route('workspaces.usage', $workspace));

        $response->assertOk();
        $response->assertJsonPath('documents.used', 1);
        $response->assertJsonPath('documents.limit', 100);
        $response->assertJsonPath('attachments.used', 1);
        $response->assertJsonPath('users.used', 1);
        $response->assertJsonPath('users.limit', null);
        $response->assertJsonPath('storage.limit', null);
        $this->assertGreaterThan(0, $response->json('storage.used'));
    }

    public function test_workspace_without_configured_limits_reports_null_limits()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->getJson(route('workspaces.usage', $workspace));

        $response->assertOk();
        $response->assertJsonPath('storage.limit', null);
        $response->assertJsonPath('users.limit', null);
        $response->assertJsonPath('documents.limit', null);
        $response->assertJsonPath('attachments.limit', null);
    }

    public function test_non_admin_member_cannot_view_workspace_usage()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($member->user)->getJson(route('workspaces.usage', $workspace));

        $response->assertForbidden();
    }

    public function test_non_member_cannot_view_workspace_usage()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($outsider->user)->getJson(route('workspaces.usage', $workspace));

        $response->assertForbidden();
    }
}
