<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Actions\Documents\UploadAttachment;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentApiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $user;

    private Document $document;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('archivum.attachments.disk'));

        $this->workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($this->workspace)->create(['role' => WorkspaceRole::Admin]);
        $this->user = $member->user;
        $type = DocumentType::factory()->for($this->workspace)->create();
        $this->document = Document::factory()->for($this->workspace)->for($type)->create();
        $this->token = $this->user->createToken('CLI')->plainTextToken;
    }

    private function upload(string $name = 'scan.pdf'): DocumentAttachment
    {
        return app(UploadAttachment::class)->handle(
            $this->document,
            UploadedFile::fake()->create($name, 12),
            $this->user,
        );
    }

    public function test_files_can_be_uploaded_onto_a_document()
    {
        $response = $this->withToken($this->token)->postJson(
            "/api/v1/documents/{$this->document->id}/attachments",
            [
                'files' => [
                    UploadedFile::fake()->create('front.pdf', 10),
                    UploadedFile::fake()->create('back.pdf', 10),
                ],
            ],
        );

        $response->assertCreated()
            ->assertJsonCount(2, 'data')
            // Whatever the reading ended up as, it is a status and not null:
            // the column is NOT NULL with a database-side default, so
            // serializing what `create()` held in memory would misstate it.
            ->assertJsonPath('data.0.ocr_status', fn (?string $status): bool => $status !== null);

        $this->assertCount(2, $this->document->refresh()->attachments);
    }

    public function test_a_document_s_attachments_can_be_listed()
    {
        $attachment = $this->upload();

        $this->withToken($this->token)
            ->getJson("/api/v1/documents/{$this->document->id}/attachments")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $attachment->id)
            ->assertJsonPath('data.0.filename', 'scan.pdf');
    }

    /**
     * The listing would otherwise hand back every page of every reading.
     */
    public function test_the_reading_travels_with_one_attachment_and_not_with_the_listing()
    {
        $attachment = $this->upload();
        $attachment->forceFill(['ocr_text' => 'RENTAL AGREEMENT'])->save();

        $this->withToken($this->token)
            ->getJson("/api/v1/attachments/{$attachment->id}")
            ->assertOk()
            ->assertJsonPath('data.ocr_text', 'RENTAL AGREEMENT');

        $this->withToken($this->token)
            ->getJson("/api/v1/documents/{$this->document->id}/attachments")
            ->assertOk()
            ->assertJsonMissingPath('data.0.ocr_text');
    }

    /**
     * GET on an attachment is the metadata here, unlike the web route of the
     * same shape, which is the download. The bytes are at /file.
     */
    public function test_the_file_is_served_from_its_own_endpoint()
    {
        $attachment = $this->upload();

        $this->withToken($this->token)
            ->get("/api/v1/attachments/{$attachment->id}/file")
            ->assertOk()
            ->assertDownload('scan.pdf');
    }

    public function test_a_file_that_is_not_safe_to_render_is_not_served_inline()
    {
        $attachment = $this->upload('page.html');

        $response = $this->withToken($this->token)->get("/api/v1/attachments/{$attachment->id}/preview");

        $response->assertOk();
        $this->assertSame('application/octet-stream', $response->headers->get('Content-Type'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_an_attachment_s_file_can_be_replaced_and_the_old_one_kept()
    {
        $attachment = $this->upload();

        $response = $this->withToken($this->token)->post(
            "/api/v1/attachments/{$attachment->id}/file",
            ['file' => UploadedFile::fake()->create('rescan.pdf', 20)],
        );

        $response->assertOk()
            ->assertJsonPath('data.filename', 'rescan.pdf')
            ->assertJsonCount(1, 'data.versions')
            ->assertJsonPath('data.versions.0.filename', 'scan.pdf');
    }

    public function test_deleting_an_attachment_trashes_it()
    {
        $attachment = $this->upload();

        $this->withToken($this->token)
            ->deleteJson("/api/v1/attachments/{$attachment->id}")
            ->assertNoContent();

        $this->assertSoftDeleted('document_attachments', ['id' => $attachment->id]);
        Storage::disk($attachment->disk)->assertExists($attachment->path);
    }

    public function test_a_reading_can_be_refused_and_stops_feeding_the_search()
    {
        $attachment = $this->upload();
        $attachment->forceFill(['ocr_text' => 'GIBBERISH'])->save();
        $this->document->refreshOcrText();

        $this->withToken($this->token)
            ->deleteJson("/api/v1/attachments/{$attachment->id}/reading")
            ->assertOk();

        $this->assertNull($attachment->refresh()->ocr_text);
        $this->assertStringNotContainsString('GIBBERISH', (string) $this->document->refresh()->ocr_text);
    }

    public function test_a_reading_can_be_kept()
    {
        $attachment = $this->upload();
        $attachment->forceFill(['ocr_text' => 'RENTAL AGREEMENT'])->save();

        $this->withToken($this->token)
            ->postJson("/api/v1/attachments/{$attachment->id}/reading")
            ->assertOk();

        $this->assertSame('RENTAL AGREEMENT', $attachment->refresh()->ocr_text);
    }

    public function test_an_attachment_in_another_workspace_is_out_of_reach()
    {
        $stranger = Workspace::factory()->create();
        $strangerMember = WorkspaceUser::factory()->for($stranger)->create(['role' => WorkspaceRole::Admin]);
        $strangerType = DocumentType::factory()->for($stranger)->create();
        $strangerDocument = Document::factory()->for($stranger)->for($strangerType)->create();
        $strangerAttachment = app(UploadAttachment::class)->handle(
            $strangerDocument,
            UploadedFile::fake()->create('theirs.pdf', 10),
            $strangerMember->user,
        );

        $this->withToken($this->token)
            ->getJson("/api/v1/attachments/{$strangerAttachment->id}")
            ->assertForbidden();

        $this->withToken($this->token)
            ->get("/api/v1/attachments/{$strangerAttachment->id}/file")
            ->assertForbidden();
    }

    public function test_an_upload_with_no_files_is_a_validation_error()
    {
        $this->withToken($this->token)
            ->postJson("/api/v1/documents/{$this->document->id}/attachments", [])
            ->assertStatus(422)
            ->assertJsonStructure(['message', 'errors' => ['files']]);
    }

    /**
     * A 201 answers with what it created. Re-reading the document's whole
     * attachment list to get the database-side defaults would report every
     * scan already on it as though this upload had made them, and a client
     * that files the response as "the new ones" would be wrong about all of
     * them.
     */
    public function test_the_upload_answers_with_what_it_created_and_nothing_else()
    {
        $existing = $this->upload('already-here.pdf');

        $response = $this->withToken($this->token)->postJson(
            "/api/v1/documents/{$this->document->id}/attachments",
            ['files' => [UploadedFile::fake()->create('new.pdf', 10)]],
        );

        $response->assertCreated()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.filename', 'new.pdf');

        $this->assertNotContains($existing->id, array_column($response->json('data'), 'id'));
        $this->assertCount(2, $this->document->refresh()->attachments);
    }
}
