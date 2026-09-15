<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class WorkspaceMemberApiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

        $this->workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($this->workspace)->create(['role' => WorkspaceRole::Admin]);
        $this->admin = $member->user;
        $this->token = $this->admin->createToken('CLI')->plainTextToken;
    }

    public function test_members_are_listed_with_their_roles()
    {
        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/users")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $this->admin->id)
            ->assertJsonPath('data.0.role', WorkspaceRole::Admin->value);
    }

    public function test_somebody_with_an_account_can_be_added()
    {
        $existing = User::factory()->create();

        $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/users", [
                'email' => $existing->email,
                'name' => $existing->name,
                'role' => WorkspaceRole::User->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.id', $existing->id)
            ->assertJsonPath('data.role', WorkspaceRole::User->value);

        $this->assertDatabaseHas('workspace_user', [
            'workspace_id' => $this->workspace->id,
            'user_id' => $existing->id,
        ]);
    }

    public function test_somebody_without_an_account_is_invited()
    {
        $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/users", [
                'email' => 'newcomer@example.test',
                'name' => 'Newcomer',
                'role' => WorkspaceRole::User->value,
            ])
            ->assertCreated()
            ->assertJsonPath('data.email', 'newcomer@example.test');

        $this->assertDatabaseHas('users', ['email' => 'newcomer@example.test']);
    }

    public function test_a_member_s_role_can_be_changed()
    {
        $other = WorkspaceUser::factory()->for($this->workspace)->create(['role' => WorkspaceRole::User]);

        $this->withToken($this->token)
            ->patchJson(
                "/api/v1/workspaces/{$this->workspace->id}/users/{$other->user->id}",
                ['role' => WorkspaceRole::Admin->value],
            )
            ->assertOk()
            ->assertJsonPath('data.role', WorkspaceRole::Admin->value);
    }

    public function test_a_member_can_be_removed()
    {
        $other = WorkspaceUser::factory()->for($this->workspace)->create(['role' => WorkspaceRole::User]);

        $this->withToken($this->token)
            ->deleteJson("/api/v1/workspaces/{$this->workspace->id}/users/{$other->user->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('workspace_user', [
            'workspace_id' => $this->workspace->id,
            'user_id' => $other->user->id,
        ]);
    }

    /**
     * The guard that stops a workspace being left with nobody who can
     * administer it holds on this side too.
     */
    public function test_the_last_admin_cannot_be_demoted_or_removed()
    {
        $this->withToken($this->token)
            ->patchJson(
                "/api/v1/workspaces/{$this->workspace->id}/users/{$this->admin->id}",
                ['role' => WorkspaceRole::User->value],
            )
            ->assertStatus(422);

        $this->withToken($this->token)
            ->deleteJson("/api/v1/workspaces/{$this->workspace->id}/users/{$this->admin->id}")
            ->assertStatus(422);

        $this->assertDatabaseHas('workspace_user', [
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->admin->id,
            'role' => WorkspaceRole::Admin->value,
        ]);
    }

    public function test_somebody_who_is_not_a_member_is_not_found()
    {
        $stranger = User::factory()->create();

        $this->withToken($this->token)
            ->deleteJson("/api/v1/workspaces/{$this->workspace->id}/users/{$stranger->id}")
            ->assertNotFound();
    }

    public function test_a_plain_member_cannot_add_or_remove_anybody()
    {
        $member = WorkspaceUser::factory()->for($this->workspace)->create(['role' => WorkspaceRole::User]);
        $token = $member->user->createToken('CLI')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/users", [
                'email' => 'newcomer@example.test',
                'name' => 'Newcomer',
                'role' => WorkspaceRole::Admin->value,
            ])
            ->assertForbidden();

        $this->withToken($token)
            ->deleteJson("/api/v1/workspaces/{$this->workspace->id}/users/{$this->admin->id}")
            ->assertForbidden();
    }
}
