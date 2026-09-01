<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Tag;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class DocumentIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_sees_only_their_workspaces_documents()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        Document::factory()->for($workspace)->for($type, 'documentType')->create(['title' => 'Mine']);
        Document::factory()->create(['title' => 'Theirs']);

        $this->actingAs($member->user)
            ->get(route('documents.index', $workspace))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('documents/index')
                ->has('documents.data', 1)
                ->where('documents.data.0.title', 'Mine'),
            );
    }

    public function test_filters_narrow_results()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $typeA = DocumentType::factory()->for($workspace)->create();
        $typeB = DocumentType::factory()->for($workspace)->create();
        Document::factory()->for($workspace)->for($typeA, 'documentType')->create(['title' => 'Invoice one']);
        Document::factory()->for($workspace)->for($typeB, 'documentType')->create(['title' => 'Contract one']);

        $this->actingAs($member->user)
            ->get(route('documents.index', $workspace) . '?document_type_id=' . $typeA->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.title', 'Invoice one'),
            );
    }

    public function test_index_exposes_pagination_metadata_covering_every_match()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        Document::factory()->for($workspace)->count(20)->create();

        $this->actingAs($member->user)
            ->get(route('documents.index', $workspace))
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 15)
                ->where('documents.meta.total', 20)
                ->where('documents.meta.from', 1)
                ->where('documents.meta.to', 15)
                ->where('documents.links.prev', null)
                ->whereNot('documents.links.next', null),
            );
    }

    public function test_pagination_links_carry_the_active_filters()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        Document::factory()->for($workspace)->for($type, 'documentType')->count(20)->create();

        $this->actingAs($member->user)
            ->get(route('documents.index', $workspace) . '?document_type_id=' . $type->id)
            ->assertInertia(fn (Assert $page) => $page
                ->where('documents.meta.total', 20)
                ->where(
                    'documents.links.next',
                    fn (?string $next) => $next !== null && str_contains($next, 'document_type_id=' . $type->id),
                ),
            );
    }

    public function test_tag_filter_narrows_results()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $tag = Tag::factory()->for($workspace)->create();
        $tagged = Document::factory()->for($workspace)->create(['title' => 'Tagged']);
        $tagged->tags()->attach($tag);
        Document::factory()->for($workspace)->create(['title' => 'Untagged']);

        $this->actingAs($member->user)
            ->get(route('documents.index', $workspace) . '?tag_ids[]=' . $tag->id)
            ->assertInertia(fn (Assert $page) => $page
                ->has('documents.data', 1)
                ->where('documents.data.0.title', 'Tagged'),
            );
    }

    public function test_non_member_cannot_view_the_index()
    {
        $workspace = Workspace::factory()->create();
        $outsider = WorkspaceUser::factory()->create(['role' => WorkspaceRole::Admin]);

        $this->actingAs($outsider->user)
            ->get(route('documents.index', $workspace))
            ->assertForbidden();
    }

    public function test_the_index_carries_a_numbered_page_window()
    {
        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        Document::factory()->count(50)->for($workspace)->for($type, 'documentType')->create();

        $this->actingAs($member->user)
            ->get(route('documents.index', ['workspace' => $workspace, 'page' => 2]))
            ->assertOk()
            ->assertInertia(function (Assert $page) {
                /** @var list<array{url: string|null, label: string, active: bool}> $links */
                $links = $page->toArray()['props']['documents']['meta']['links'];

                $numbered = array_values(array_filter(
                    $links,
                    static fn (array $link): bool => ctype_digit($link['label']),
                ));

                // onEachSide(1) over four pages, from page two: 1 2 3 4.
                $this->assertSame(
                    ['1', '2', '3', '4'],
                    array_column($numbered, 'label'),
                );

                $active = array_values(array_filter(
                    $numbered,
                    static fn (array $link): bool => $link['active'],
                ));

                $this->assertCount(1, $active);
                $this->assertSame('2', $active[0]['label']);
            });
    }
}
