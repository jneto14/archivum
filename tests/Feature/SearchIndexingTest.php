<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\UploadAttachment;
use App\Enums\WorkspaceRole;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * An internal application has nothing to rank, and a login page in a search
 * index quietly announces that an organisation keeps an archive at a particular
 * address.
 *
 * The header is asserted rather than only the meta tag because a meta tag only
 * covers HTML: attachment downloads and previews are served by routes too, and
 * those are the responses whose contents would matter most.
 */
class SearchIndexingTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_login_page_asks_not_to_be_indexed()
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertHeader('x-robots-tag', 'noindex, nofollow');
        $response->assertSee('name="robots"', false);
    }

    public function test_an_attachment_download_asks_not_to_be_indexed()
    {
        Storage::fake('local');
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        $attachment = app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->create('scan.pdf', 10, 'application/pdf'),
            $member->user,
        );

        $this->actingAs($member->user)
            ->get(route('attachments.show', $attachment))
            ->assertHeader('x-robots-tag', 'noindex, nofollow');
    }

    /**
     * The file is the half a crawler reads first, and it is easy to leave as
     * the framework's permissive default — which is what it was.
     */
    public function test_robots_txt_disallows_everything()
    {
        $robots = file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('User-agent: *', (string) $robots);
        $this->assertMatchesRegularExpression('/^Disallow: \/$/m', (string) $robots);
    }
}
