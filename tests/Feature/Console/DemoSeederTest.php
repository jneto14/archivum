<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Enums\OcrStatus;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\OrganizationNode;
use App\Models\User;
use App\Models\Workspace;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The dataset a demo wakes up to.
 *
 * An empty archive demonstrates nothing, so "it seeded without erroring" is not
 * the bar. What matters is that a visitor arriving cold finds documents already
 * filed on shelves and a search that returns something on the first query —
 * which means the attachments have to arrive with their text already extracted,
 * not queued behind a worker.
 */
class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        config()->set('archivum.demo.email', 'demo@archivum.test');
        config()->set('archivum.demo.password', 'demo1234');
    }

    public function test_it_seeds_an_archive_that_is_already_in_use()
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(1, Workspace::query()->count());
        $this->assertGreaterThan(0, Document::query()->count());

        // Filed, not just created: the split between where a document is and
        // how it is found is the thing the demo exists to show.
        $this->assertGreaterThan(0, OrganizationNode::query()->count());
        $this->assertSame(
            Document::query()->count(),
            Document::query()->has('locations')->count(),
        );
    }

    public function test_the_offered_credentials_actually_sign_in()
    {
        $this->seed(DemoSeeder::class);

        $this->post(route('login.store'), [
            'email' => 'demo@archivum.test',
            'password' => 'demo1234',
        ])->assertSessionHasNoErrors();

        $this->assertAuthenticated();
    }

    /**
     * The demo's whole claim is that a document is findable by what is written
     * on the page. Seeding attachments whose text is still pending would leave
     * search empty for the first minutes after every reset.
     */
    public function test_attachments_arrive_with_their_text_already_extracted()
    {
        $this->seed(DemoSeeder::class);

        $attachments = DocumentAttachment::query()->get();

        $this->assertGreaterThan(0, $attachments->count());

        foreach ($attachments as $attachment) {
            $this->assertSame(OcrStatus::Completed, $attachment->ocr_status);
            $this->assertNotEmpty($attachment->ocr_text);
            Storage::disk('local')->assertExists($attachment->path);
        }
    }

    public function test_the_seeded_text_is_the_real_content_of_the_sample_files()
    {
        $this->seed(DemoSeeder::class);

        $invoice = DocumentAttachment::query()
            ->where('filename', 'invoice-2026-0184.pdf')
            ->firstOrFail();

        $this->assertStringContainsString('NORTHGATE STATIONERY', $invoice->ocr_text);
    }

    /**
     * Anyone can sign into a demo, so an upload spree between two resets would
     * otherwise fill the volume — the reset would clear it, but only after the
     * disk was already full.
     */
    public function test_the_seeded_workspace_has_limits()
    {
        $this->seed(DemoSeeder::class);

        $limits = Workspace::query()->firstOrFail()->limits;

        $this->assertNotNull($limits);
        $this->assertGreaterThan(0, $limits->storage_bytes);
        $this->assertGreaterThan(0, $limits->documents);
    }

    public function test_the_demo_account_can_administer_its_workspace()
    {
        $this->seed(DemoSeeder::class);

        $user = User::query()->where('email', 'demo@archivum.test')->firstOrFail();

        $this->assertTrue($user->is_platform_admin);
        $this->assertNotNull($user->email_verified_at);
    }
}
