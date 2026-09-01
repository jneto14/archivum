<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\UploadAttachment;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLimit;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceLimitTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_user_beyond_the_workspace_user_limit_fails_validation()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        WorkspaceLimit::factory()->for($workspace)->create(['users' => 1]);
        $newUser = User::factory()->create();

        $response = $this->actingAs($admin->user)->post(route('workspaces.users.store', $workspace), [
            'email' => $newUser->email,
            'role' => WorkspaceRole::User->value,
        ]);

        $response->assertSessionHasErrors('email');
        $this->assertFalse($workspace->isMember($newUser));
    }

    public function test_creating_a_document_beyond_the_workspace_document_limit_fails_validation()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Existing', null, null);
        WorkspaceLimit::factory()->for($workspace)->create(['documents' => 1]);

        $response = $this->actingAs($member->user)->post(route('documents.store', $workspace), [
            'document_type_id' => $type->id,
            'title' => 'Should not fit',
        ]);

        $response->assertSessionHasErrors('workspace');
        $this->assertDatabaseMissing('documents', ['title' => 'Should not fit']);
    }

    public function test_uploading_an_attachment_beyond_the_workspace_attachment_limit_fails_validation()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);
        app(UploadAttachment::class)->handle($document, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'), $member->user);
        WorkspaceLimit::factory()->for($workspace)->create(['attachments' => 1]);

        $response = $this->actingAs($member->user)->post(route('attachments.store', $document), [
            'files' => [UploadedFile::fake()->create('b.pdf', 10, 'application/pdf')],
        ]);

        $response->assertSessionHasErrors('files');
        $this->assertDatabaseCount('document_attachments', 1);
    }

    public function test_uploading_a_file_that_would_exceed_the_workspace_storage_limit_fails_validation()
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);
        WorkspaceLimit::factory()->for($workspace)->create(['storage_bytes' => 1000]);

        $response = $this->actingAs($member->user)->post(route('attachments.store', $document), [
            'files' => [UploadedFile::fake()->create('large.pdf', 10, 'application/pdf')],
        ]);

        $response->assertSessionHasErrors('files');
        $this->assertDatabaseCount('document_attachments', 0);
    }

    public function test_a_workspace_without_configured_limits_is_unlimited()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $newUser = User::factory()->create();

        $response = $this->actingAs($admin->user)->post(route('workspaces.users.store', $workspace), [
            'email' => $newUser->email,
            'role' => WorkspaceRole::User->value,
        ]);

        $response->assertRedirect();
        $this->assertTrue($workspace->isMember($newUser));
    }

    public function test_platform_admin_can_set_a_workspaces_limits()
    {
        $workspace = Workspace::factory()->create();
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $response = $this->actingAs($platformAdmin)->patch(route('workspaces.limits.update', $workspace), [
            'storage_bytes' => 1024 * 1024 * 1024,
            'users' => 10,
            'documents' => 500,
            'attachments' => 1000,
        ]);

        $response->assertRedirect();

        $limits = $workspace->limits()->sole();
        $this->assertSame(1024 * 1024 * 1024, $limits->storage_bytes);
        $this->assertSame(10, $limits->users);
        $this->assertSame(500, $limits->documents);
        $this->assertSame(1000, $limits->attachments);
    }

    public function test_platform_admin_can_clear_a_workspaces_limits_to_unlimited()
    {
        $workspace = Workspace::factory()->create();
        WorkspaceLimit::factory()->for($workspace)->create(['users' => 5]);
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $response = $this->actingAs($platformAdmin)->patch(route('workspaces.limits.update', $workspace), [
            'storage_bytes' => null,
            'users' => null,
            'documents' => null,
            'attachments' => null,
        ]);

        $response->assertRedirect();
        $this->assertNull($workspace->limits()->sole()->users);
    }

    public function test_workspace_admin_cannot_update_their_own_workspaces_limits()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->patch(route('workspaces.limits.update', $workspace), [
            'users' => 10,
        ]);

        $response->assertForbidden();
        $this->assertNull($workspace->limits()->first());
    }

    public function test_updating_limits_rejects_negative_values()
    {
        $workspace = Workspace::factory()->create();
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $response = $this->actingAs($platformAdmin)->patch(route('workspaces.limits.update', $workspace), [
            'documents' => -1,
        ]);

        $response->assertSessionHasErrors('documents');
    }
}
