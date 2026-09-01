<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\CreateOrganizationRule;
use App\Actions\Organization\CreateScheme;
use App\Actions\Organization\UpdateOrganizationRule;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\OrganizationRule;
use App\Models\OrganizationScheme;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

/**
 * Rules decide where a document is filed, so the ways they can be wrong all
 * end with documents in the wrong drawer.
 *
 * Two of those ways are cross-scheme: a rule may only target a level of its own
 * scheme, and the route's `{scheme}` and `{rule}` are bound independently, so
 * nothing but an explicit check stops a rule being edited through a scheme it
 * does not belong to.
 */
class OrganizationRuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_admin_can_add_a_rule_to_a_scheme()
    {
        [$workspace, $admin] = $this->workspaceWithAdmin();
        $scheme = $this->createScheme($workspace);
        $letter = $scheme->levels()->where('key', 'letter')->firstOrFail();

        $response = $this->actingAs($admin)->post(route('organization.schemes.rules.store', $scheme), [
            'matcher_key' => 'document_type',
            'matcher_value' => 'invoice',
            'target_level_id' => $letter->id,
            'preferred_value' => 'A',
        ]);

        $response->assertRedirect();

        $rule = OrganizationRule::query()->where('scheme_id', $scheme->id)->firstOrFail();

        $this->assertSame('document_type', $rule->matcher_key);
        $this->assertSame('invoice', $rule->matcher_value);
        $this->assertSame($letter->id, $rule->target_level_id);
        $this->assertSame('A', $rule->preferred_value);
    }

    public function test_a_rule_cannot_target_a_level_of_another_scheme()
    {
        [$workspace, $admin] = $this->workspaceWithAdmin();
        $scheme = $this->createScheme($workspace);
        $other = $this->otherSchemeFor($admin);
        $foreignLevel = $other->levels()->where('key', 'letter')->firstOrFail();

        $response = $this->actingAs($admin)->post(route('organization.schemes.rules.store', $scheme), [
            'matcher_key' => 'document_type',
            'matcher_value' => 'invoice',
            'target_level_id' => $foreignLevel->id,
            'preferred_value' => 'A',
        ]);

        $response->assertNotFound();
        $this->assertSame(0, OrganizationRule::query()->count());
    }

    public function test_two_rules_cannot_share_a_matcher_within_a_scheme()
    {
        [$workspace, $admin] = $this->workspaceWithAdmin();
        $scheme = $this->createScheme($workspace);
        $letter = $scheme->levels()->where('key', 'letter')->firstOrFail();

        app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $letter, 'A');

        $response = $this->actingAs($admin)->post(route('organization.schemes.rules.store', $scheme), [
            'matcher_key' => 'document_type',
            'matcher_value' => 'invoice',
            'target_level_id' => $letter->id,
            'preferred_value' => 'B',
        ]);

        $response->assertSessionHasErrors('matcher_value');
        $this->assertSame(1, OrganizationRule::query()->count());
    }

    public function test_an_admin_can_change_a_rules_matcher_and_target()
    {
        [$workspace, $admin] = $this->workspaceWithAdmin();
        $scheme = $this->createScheme($workspace);
        $letter = $scheme->levels()->where('key', 'letter')->firstOrFail();
        $position = $scheme->levels()->where('key', 'position')->firstOrFail();

        $rule = app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $letter, 'A');

        $response = $this->actingAs($admin)->patch(
            route('organization.schemes.rules.update', [$scheme, $rule]),
            [
                'matcher_key' => 'tag',
                'matcher_value' => 'utilities',
                'target_level_id' => $position->id,
                'preferred_value' => '07',
            ],
        );

        $response->assertRedirect();

        $rule->refresh();

        $this->assertSame('tag', $rule->matcher_key);
        $this->assertSame('utilities', $rule->matcher_value);
        $this->assertSame($position->id, $rule->target_level_id);
        $this->assertSame('07', $rule->preferred_value);
    }

    public function test_a_rule_keeps_its_own_matcher_when_only_the_target_changes()
    {
        [$workspace, $admin] = $this->workspaceWithAdmin();
        $scheme = $this->createScheme($workspace);
        $letter = $scheme->levels()->where('key', 'letter')->firstOrFail();
        $position = $scheme->levels()->where('key', 'position')->firstOrFail();

        $rule = app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $letter, 'A');

        // The uniqueness check has to exclude the rule being edited, or a rule
        // could never be saved without also changing its matcher.
        $response = $this->actingAs($admin)->patch(
            route('organization.schemes.rules.update', [$scheme, $rule]),
            [
                'matcher_key' => 'document_type',
                'matcher_value' => 'invoice',
                'target_level_id' => $position->id,
                'preferred_value' => '07',
            ],
        );

        $response->assertRedirect();
        $response->assertSessionHasNoErrors();
        $this->assertSame($position->id, $rule->refresh()->target_level_id);
    }

    public function test_updating_a_rule_cannot_collide_with_another_rules_matcher()
    {
        [$workspace, $admin] = $this->workspaceWithAdmin();
        $scheme = $this->createScheme($workspace);
        $letter = $scheme->levels()->where('key', 'letter')->firstOrFail();

        app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $letter, 'A');
        $second = app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'receipt', $letter, 'B');

        $response = $this->actingAs($admin)->patch(
            route('organization.schemes.rules.update', [$scheme, $second]),
            [
                'matcher_key' => 'document_type',
                'matcher_value' => 'invoice',
                'target_level_id' => $letter->id,
                'preferred_value' => 'B',
            ],
        );

        $response->assertSessionHasErrors('matcher_value');
        $this->assertSame('receipt', $second->refresh()->matcher_value);
    }

    public function test_a_rule_cannot_be_edited_through_a_scheme_it_does_not_belong_to()
    {
        [$workspace, $admin] = $this->workspaceWithAdmin();
        $scheme = $this->createScheme($workspace);
        $other = $this->otherSchemeFor($admin);
        $letter = $scheme->levels()->where('key', 'letter')->firstOrFail();

        $rule = app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $letter, 'A');

        $response = $this->actingAs($admin)->patch(
            route('organization.schemes.rules.update', [$other, $rule]),
            [
                'matcher_key' => 'tag',
                'matcher_value' => 'utilities',
                'target_level_id' => $other->levels()->where('key', 'letter')->firstOrFail()->id,
                'preferred_value' => 'Z',
            ],
        );

        $response->assertNotFound();
        $this->assertSame('document_type', $rule->refresh()->matcher_key);
    }

    public function test_an_admin_can_delete_a_rule()
    {
        [$workspace, $admin] = $this->workspaceWithAdmin();
        $scheme = $this->createScheme($workspace);
        $letter = $scheme->levels()->where('key', 'letter')->firstOrFail();

        $rule = app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $letter, 'A');

        $this->actingAs($admin)
            ->delete(route('organization.schemes.rules.destroy', [$scheme, $rule]))
            ->assertRedirect();

        $this->assertSame(0, OrganizationRule::query()->count());
    }

    public function test_a_rule_cannot_be_deleted_through_a_scheme_it_does_not_belong_to()
    {
        [$workspace, $admin] = $this->workspaceWithAdmin();
        $scheme = $this->createScheme($workspace);
        $other = $this->otherSchemeFor($admin);
        $letter = $scheme->levels()->where('key', 'letter')->firstOrFail();

        $rule = app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $letter, 'A');

        $this->actingAs($admin)
            ->delete(route('organization.schemes.rules.destroy', [$other, $rule]))
            ->assertNotFound();

        $this->assertSame(1, OrganizationRule::query()->count());
    }

    public function test_a_plain_member_cannot_touch_rules()
    {
        [$workspace, $admin] = $this->workspaceWithAdmin();
        $scheme = $this->createScheme($workspace);
        $letter = $scheme->levels()->where('key', 'letter')->firstOrFail();
        $rule = app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $letter, 'A');

        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);

        $this->actingAs($member->user)
            ->post(route('organization.schemes.rules.store', $scheme), [
                'matcher_key' => 'tag',
                'matcher_value' => 'utilities',
                'target_level_id' => $letter->id,
                'preferred_value' => 'B',
            ])
            ->assertForbidden();

        $this->actingAs($member->user)
            ->delete(route('organization.schemes.rules.destroy', [$scheme, $rule]))
            ->assertForbidden();

        $this->assertSame(1, OrganizationRule::query()->count());
    }

    public function test_the_create_action_refuses_a_target_level_from_another_scheme()
    {
        [$workspace, $admin] = $this->workspaceWithAdmin();
        $scheme = $this->createScheme($workspace);
        $foreignLevel = $this->otherSchemeFor($admin)->levels()->where('key', 'letter')->firstOrFail();

        // The controller resolves the level out of the scheme and 404s first,
        // so the action's own guard is only reachable by calling it directly —
        // which is what any other caller of the action would hit.
        $this->expectException(ValidationException::class);

        app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $foreignLevel, 'A');
    }

    public function test_the_update_action_refuses_a_target_level_from_another_scheme()
    {
        [$workspace, $admin] = $this->workspaceWithAdmin();
        $scheme = $this->createScheme($workspace);
        $letter = $scheme->levels()->where('key', 'letter')->firstOrFail();
        $foreignLevel = $this->otherSchemeFor($admin)->levels()->where('key', 'letter')->firstOrFail();

        $rule = app(CreateOrganizationRule::class)->handle($scheme, 'document_type', 'invoice', $letter, 'A');

        try {
            app(UpdateOrganizationRule::class)->handle($rule, 'document_type', 'invoice', $foreignLevel, 'A');
            $this->fail('A rule must not be moved onto a level of another scheme.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('target_level_id', $exception->errors());
        }

        $this->assertSame(
            $letter->id,
            $rule->refresh()->target_level_id,
            'A refused update must leave the rule pointing where it was.',
        );
    }

    /**
     * @return array{Workspace, User}
     */
    private function workspaceWithAdmin(): array
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);

        return [$workspace, $admin->user];
    }

    /**
     * A second scheme the same admin can reach, in its own workspace.
     *
     * A workspace may only hold one scheme, so "another scheme" always means
     * another workspace — which is exactly the shape the cross-scheme guards
     * in the controller exist for.
     */
    private function otherSchemeFor(User $admin): OrganizationScheme
    {
        $workspace = Workspace::factory()->create();

        WorkspaceUser::factory()->for($workspace)->create([
            'user_id' => $admin->id,
            'role' => WorkspaceRole::Admin,
        ]);

        return $this->createScheme($workspace, 'Second Archive');
    }

    private function createScheme(Workspace $workspace, string $name = 'Traditional Archive'): OrganizationScheme
    {
        return app(CreateScheme::class)->handle($workspace, $name, [
            ['name' => 'Cover', 'key' => 'cover', 'value_strategy' => NodeValueStrategy::Sequential],
            ['name' => 'Letter', 'key' => 'letter', 'value_strategy' => NodeValueStrategy::Manual],
            ['name' => 'Position', 'key' => 'position', 'value_strategy' => NodeValueStrategy::Sequential],
        ]);
    }
}
