<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\UploadAttachment;
use App\Enums\WorkspaceRole;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * An attachment is a file somebody uploaded, served from the application's own
 * origin. Uploads are not restricted by type on purpose — an archive should
 * take whatever its owner has — so the safety has to live in how the file is
 * served, not in what may be stored.
 *
 * Before this, the preview route handed the browser whatever type the stored
 * file looked like. An uploaded `invoice.html` came back as `text/html` and ran
 * its script with the viewer's session; an `.svg` did the same while passing as
 * an image. On a demo installation, where the credentials are published and
 * anyone can upload, that was reachable by anybody.
 */
class AttachmentInlineSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_uploaded_html_file_is_never_served_as_html()
    {
        $response = $this->previewUpload(
            UploadedFile::fake()->createWithContent('invoice.html', '<script>alert(document.cookie)</script>'),
        );

        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('content-type'));
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('content-disposition'));
    }

    /**
     * The one that looks harmless. An SVG is `image/*`, so any rule phrased as
     * "images are safe to show" lets it through — and an SVG is a document that
     * can carry script.
     */
    public function test_an_uploaded_svg_is_never_rendered_inline()
    {
        $response = $this->previewUpload(
            UploadedFile::fake()->createWithContent(
                'diagram.svg',
                '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>',
            ),
        );

        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('content-type'));
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('content-disposition'));
    }

    public function test_every_attachment_response_forbids_content_sniffing()
    {
        $attachment = $this->upload(
            UploadedFile::fake()->createWithContent('notes.txt', 'plain'),
        );

        $user = $attachment->document->workspace->users()->firstOrFail();

        $this->actingAs($user)
            ->get(route('attachments.preview', $attachment))
            ->assertHeader('x-content-type-options', 'nosniff');

        $this->actingAs($user)
            ->get(route('attachments.show', $attachment))
            ->assertHeader('x-content-type-options', 'nosniff');
    }

    /**
     * The preview has to keep working for what it exists to show, or the fix
     * has traded a security hole for a broken feature.
     */
    public function test_a_pdf_is_still_previewed_inline()
    {
        $response = $this->previewUpload(
            UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        );

        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('content-disposition'));
    }

    public function test_a_raster_image_is_still_previewed_inline()
    {
        $response = $this->previewUpload(
            UploadedFile::fake()->image('photo.png'),
        );

        $response->assertOk();
        $this->assertSame('image/png', $response->headers->get('content-type'));
        $this->assertStringStartsWith('inline;', (string) $response->headers->get('content-disposition'));
    }

    /**
     * The download route is not the risk — it never rendered anything — but it
     * must not become a second way in.
     */
    public function test_the_download_route_never_serves_html_either()
    {
        $attachment = $this->upload(
            UploadedFile::fake()->createWithContent('invoice.html', '<script>alert(1)</script>'),
        );

        $response = $this
            ->actingAs($attachment->document->workspace->users()->firstOrFail())
            ->get(route('attachments.show', $attachment));

        $response->assertOk();
        $this->assertStringStartsWith('attachment;', (string) $response->headers->get('content-disposition'));
    }

    /**
     * The interface has to reach the same verdict as the response does. It
     * previously worked it out from the mime type on its own, which put a
     * broken image where the "cannot preview" message belonged the moment the
     * server stopped serving SVGs inline.
     */
    public function test_the_serialised_attachment_agrees_with_how_it_is_served()
    {
        foreach ([
            ['scan.pdf', 'application/pdf', true],
            ['diagram.svg', 'image/svg+xml', false],
            ['invoice.html', 'text/html', false],
        ] as [$filename, $mime, $expected]) {
            $attachment = $this->upload(
                UploadedFile::fake()->createWithContent($filename, 'x'),
            );

            $this->assertSame(
                $expected,
                $attachment->is_previewable,
                "{$filename} previewable flag",
            );

            $this->assertArrayHasKey('is_previewable', $attachment->toArray());

            $response = $this
                ->actingAs($attachment->document->workspace->users()->firstOrFail())
                ->get(route('attachments.preview', $attachment));

            $this->assertSame(
                $expected,
                str_starts_with((string) $response->headers->get('content-disposition'), 'inline;'),
                "{$filename} served inline",
            );
        }
    }

    /**
     * The flag has to reach the page, not merely exist on the model.
     *
     * `DocumentResource` builds each attachment as an explicit array, so the
     * model appending `is_previewable` proves nothing about what the dialog is
     * handed — and the dialog reads it as the gate on every preview. Absent, it
     * is `undefined`, which is falsy, so a PDF and a photograph are both
     * "cannot preview" and the feature is off for everything at once.
     */
    public function test_the_document_page_hands_the_dialog_the_previewable_flag()
    {
        $attachment = $this->upload(
            UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
        );

        $document = $attachment->document;
        $user = $document->workspace->users()->firstOrFail();

        app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->createWithContent('diagram.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'),
            $user,
        );

        $response = $this->actingAs($user)->get(route('documents.show', $document));

        $response->assertInertia(fn (Assert $page) => $page->has('document.attachments', 2));

        $attachments = collect($response->viewData('page')['props']['document']['attachments'])
            ->keyBy('filename');

        $this->assertTrue($attachments['scan.pdf']['is_previewable'], 'a PDF is previewable');
        $this->assertFalse($attachments['diagram.svg']['is_previewable'], 'an SVG is not');
    }

    private function upload(UploadedFile $file): DocumentAttachment
    {
        Storage::fake('local');

        // The upload queues text extraction, which runs inline under the
        // suite's sync queue. Nothing here is about extraction, and letting
        // tesseract loose on an SVG fails the test for the wrong reason.
        Queue::fake();

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User]);
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member->user, $type, 'Invoice', null, null);

        return app(UploadAttachment::class)->handle($document, $file, $member->user);
    }

    private function previewUpload(UploadedFile $file): TestResponse
    {
        $attachment = $this->upload($file);

        return $this
            ->actingAs($attachment->document->workspace->users()->firstOrFail())
            ->get(route('attachments.preview', $attachment));
    }
}
