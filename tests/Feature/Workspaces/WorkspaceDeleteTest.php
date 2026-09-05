<?php

declare(strict_types=1);

namespace Tests\Feature\Workspaces;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\UploadAttachment;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use App\Support\Refusal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WorkspaceDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_delete_a_workspace_and_its_attachment_files()
    {
        Storage::fake(config('archivum.attachments.disk'));
        Workspace::factory()->create();

        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $admin->user, $type, 'Invoice', null, null);
        $attachment = app(UploadAttachment::class)->handle($document, UploadedFile::fake()->create('scan.pdf'), $admin->user);

        $response = $this->actingAs($admin->user)->delete(route('workspaces.destroy', $workspace));

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseMissing('workspaces', ['id' => $workspace->id]);
        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('document_types', ['id' => $type->id]);
        Storage::disk($attachment->disk)->assertMissing($attachment->path);
    }

    public function test_non_admin_member_cannot_delete_a_workspace()
    {
        Workspace::factory()->create();
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $response = $this->actingAs($member->user)->delete(route('workspaces.destroy', $workspace));

        $response->assertForbidden();
        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
    }

    public function test_the_only_workspace_in_the_instance_cannot_be_deleted()
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        $response = $this->actingAs($admin->user)->delete(route('workspaces.destroy', $workspace));

        $response->assertSessionHasErrors(Refusal::KEY);
        $this->assertDatabaseHas('workspaces', ['id' => $workspace->id]);
    }
}
