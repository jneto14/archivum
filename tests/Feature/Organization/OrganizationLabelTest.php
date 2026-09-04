<?php

declare(strict_types=1);

namespace Tests\Feature\Organization;

use App\Actions\Organization\CreateOrganizationNode;
use App\Actions\Organization\CreateScheme;
use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationLabelTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_nodes_label_carries_a_qr_code_and_its_path()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = $this->createScheme($workspace, labelledLevel: 'cover');
        $cover = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $this->actingAs($member->user)
            ->get(route('organization.schemes.labels', ['scheme' => $scheme, 'node_id' => $cover->id]))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('organization/labels')
                ->has('labels', 1)
                ->where('labels.0.path', '001')
                ->where('labels.0.level', 'Cover')
                // Embedded rather than fetched: a sheet of a hundred labels
                // must not race the print dialog loading its images.
                ->where('labels.0.qr', fn (string $qr) => str_starts_with($qr, 'data:image/png;base64,')),
            );
    }

    public function test_a_level_without_labels_has_none_to_print()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = $this->createScheme($workspace, labelledLevel: 'cover');
        $position = $scheme->levels->last();
        $cover = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');
        $slot = app(CreateOrganizationNode::class)->handle($position, $cover, '001');

        // A position is a slot on a page. Nothing stops a workspace enabling
        // labels there, but until it does, asking for one is refused.
        $this->actingAs($member->user)
            ->get(route('organization.schemes.labels', ['scheme' => $scheme, 'node_id' => $slot->id]))
            ->assertSessionHasErrors('level_id');

        $this->actingAs($member->user)
            ->get(route('organization.schemes.labels', ['scheme' => $scheme, 'level_id' => $position->id]))
            ->assertSessionHasErrors('level_id');
    }

    public function test_a_whole_level_prints_at_once_and_can_be_narrowed_to_one_parent()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = $this->createScheme($workspace, labelledLevel: 'position');
        [$coverLevel, $positionLevel] = $scheme->levels()->orderBy('position')->get()->all();

        $createNode = app(CreateOrganizationNode::class);
        $first = $createNode->handle($coverLevel, null, '001');
        $second = $createNode->handle($coverLevel, null, '002');
        $createNode->handle($positionLevel, $first, '001');
        $createNode->handle($positionLevel, $first, '002');
        $createNode->handle($positionLevel, $second, '001');

        $this->actingAs($member->user)
            ->get(route('organization.schemes.labels', ['scheme' => $scheme, 'level_id' => $positionLevel->id]))
            ->assertInertia(fn (Assert $page) => $page->has('labels', 3));

        // Every drawer in one cabinet, rather than every drawer there is.
        $this->actingAs($member->user)
            ->get(route('organization.schemes.labels', [
                'scheme' => $scheme,
                'level_id' => $positionLevel->id,
                'parent_id' => $first->id,
            ]))
            ->assertInertia(fn (Assert $page) => $page
                ->has('labels', 2)
                ->where('labels.0.path', '001-001'),
            );
    }

    public function test_labels_cannot_be_printed_for_another_workspaces_scheme()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);
        $scheme = $this->createScheme($workspace, labelledLevel: 'cover');
        $cover = app(CreateOrganizationNode::class)->handle($scheme->levels->first(), null, '001');

        $this->actingAs($outsider->user)
            ->get(route('organization.schemes.labels', ['scheme' => $scheme, 'node_id' => $cover->id]))
            ->assertForbidden();
    }

    public function test_a_node_from_another_scheme_cannot_be_labelled_through_this_one()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $scheme = $this->createScheme($workspace, labelledLevel: 'cover');

        $foreignScheme = $this->createScheme(Workspace::factory()->create(), labelledLevel: 'cover');
        $foreignNode = app(CreateOrganizationNode::class)->handle($foreignScheme->levels->first(), null, '001');

        $this->actingAs($member->user)
            ->get(route('organization.schemes.labels', ['scheme' => $scheme, 'node_id' => $foreignNode->id]))
            ->assertNotFound();
    }

    private function createScheme(Workspace $workspace, string $labelledLevel): OrganizationScheme
    {
        return app(CreateScheme::class)->handle($workspace, 'Traditional Archive', [
            [
                'name' => 'Cover',
                'key' => 'cover',
                'value_strategy' => NodeValueStrategy::Sequential,
                'has_printable_label' => $labelledLevel === 'cover',
            ],
            [
                'name' => 'Position',
                'key' => 'position',
                'value_strategy' => NodeValueStrategy::Sequential,
                'has_printable_label' => $labelledLevel === 'position',
            ],
        ]);
    }
}
