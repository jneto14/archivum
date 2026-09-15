<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\IntakeLabelStatus;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\IntakeLabel;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WorkspaceApiTest extends TestCase
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
     * Every other route names a workspace in its path, so this is what a
     * client has to be able to call before anything else works.
     */
    public function test_a_token_lists_only_the_workspaces_its_user_belongs_to()
    {
        Workspace::factory()->create();

        $this->withToken($this->token)
            ->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->workspace->id)
            ->assertJsonPath('data.0.users_count', 1);
    }

    public function test_a_platform_admin_sees_every_workspace()
    {
        Workspace::factory()->create();
        $admin = User::factory()->create(['is_platform_admin' => true]);

        $this->withToken($admin->createToken('CLI')->plainTextToken)
            ->getJson('/api/v1/workspaces')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_a_workspace_can_be_read_and_renamed()
    {
        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $this->workspace->id);

        $this->withToken($this->token)
            ->patchJson("/api/v1/workspaces/{$this->workspace->id}", ['name' => 'The Archive'])
            ->assertOk()
            ->assertJsonPath('data.name', 'The Archive');
    }

    public function test_a_stranger_cannot_read_a_workspace()
    {
        $stranger = Workspace::factory()->create();

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$stranger->id}")
            ->assertForbidden();
    }

    public function test_usage_reports_what_is_used_against_what_is_allowed()
    {
        $type = DocumentType::factory()->for($this->workspace)->create();
        Document::factory()->count(2)->for($this->workspace)->for($type)->create();

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/usage")
            ->assertOk()
            ->assertJsonPath('data.documents.used', 2)
            // No limit is null, which is not the same as a limit of zero.
            ->assertJsonPath('data.documents.limit', null)
            ->assertJsonPath('data.users.used', 1);
    }

    public function test_limits_are_set_by_a_platform_admin_and_reported_back()
    {
        $platformAdmin = User::factory()->create(['is_platform_admin' => true]);

        $this->withToken($platformAdmin->createToken('CLI')->plainTextToken)
            ->patchJson("/api/v1/workspaces/{$this->workspace->id}/limits", [
                'storage_bytes' => 1024,
                'documents' => 10,
                'users' => null,
                'attachments' => null,
            ])
            ->assertOk()
            ->assertJsonPath('data.documents.limit', 10)
            ->assertJsonPath('data.storage.limit', 1024)
            ->assertJsonPath('data.users.limit', null);
    }

    public function test_a_workspace_admin_cannot_raise_their_own_limits()
    {
        $this->withToken($this->token)
            ->patchJson("/api/v1/workspaces/{$this->workspace->id}/limits", ['documents' => 99999])
            ->assertForbidden();
    }

    public function test_intake_labels_are_listed_and_answered()
    {
        $label = IntakeLabel::factory()->for($this->workspace)->create([
            'status' => IntakeLabelStatus::Pending,
        ]);

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/intake-labels")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $label->id);

        $this->withToken($this->token)
            ->patchJson(
                "/api/v1/workspaces/{$this->workspace->id}/intake-labels/{$label->id}",
                ['status' => IntakeLabelStatus::Accepted->value],
            )
            ->assertOk()
            ->assertJsonPath('data.status', IntakeLabelStatus::Accepted->value);
    }

    /**
     * Reached through a workspace it does not belong to, the row does not
     * exist — there is no permission here worth explaining.
     */
    public function test_a_label_from_another_workspace_is_not_found()
    {
        $stranger = Workspace::factory()->create();
        WorkspaceUser::factory()->for($stranger)->create([
            'role' => WorkspaceRole::Admin,
            'user_id' => $this->admin->id,
        ]);
        $label = IntakeLabel::factory()->for($stranger)->create();

        $this->withToken($this->token)
            ->patchJson(
                "/api/v1/workspaces/{$this->workspace->id}/intake-labels/{$label->id}",
                ['status' => IntakeLabelStatus::Rejected->value],
            )
            ->assertNotFound();
    }
}
