<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Actions\Documents\UploadAttachment;
use App\Enums\BulkReviewAction;
use App\Enums\OcrStatus;
use App\Enums\ReviewFilter;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IntakeApiTest extends TestCase
{
    use RefreshDatabase;

    private Workspace $workspace;

    private User $admin;

    private DocumentType $type;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('archivum.attachments.disk'));

        $this->workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($this->workspace)->create(['role' => WorkspaceRole::Admin]);
        $this->admin = $member->user;
        $this->type = DocumentType::factory()->for($this->workspace)->create();
        $this->token = $this->admin->createToken('CLI')->plainTextToken;
    }

    private function documentAwaitingAReading(): Document
    {
        $document = Document::factory()->for($this->workspace)->for($this->type)->create();

        $attachment = app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->create('scan.pdf', 10),
            $this->admin,
        );

        $attachment->forceFill([
            'ocr_status' => OcrStatus::PoorlyRead,
            'ocr_text' => 'RENTAL AGREEMNT',
            'ocr_reviewed_at' => null,
        ])->save();

        return $document;
    }

    /**
     * The counts travel with the page: they are what decides which queue is
     * worth working, and a client has no tab strip to read them off.
     */
    public function test_the_queue_comes_back_with_the_counts_for_every_filter()
    {
        $document = $this->documentAwaitingAReading();

        $response = $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/review");

        $response->assertOk()
            ->assertJsonPath('data.0.id', $document->id)
            ->assertJsonPath('meta.filter', ReviewFilter::All->value)
            ->assertJsonPath('meta.counts.' . ReviewFilter::Readings->value, 1);
    }

    public function test_the_queue_can_be_filtered()
    {
        $this->documentAwaitingAReading();

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/review?filter=" . ReviewFilter::Duplicates->value)
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/review?filter=" . ReviewFilter::Readings->value)
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    /**
     * The filter travels with the answer, so "dismiss everything" means
     * everything in the queue the client was looking at (ARC-127).
     */
    public function test_many_documents_can_be_answered_at_once()
    {
        $this->documentAwaitingAReading();
        $this->documentAwaitingAReading();

        $this->withToken($this->token)
            ->postJson("/api/v1/workspaces/{$this->workspace->id}/review", [
                'action' => BulkReviewAction::DismissReadings->value,
                'filter' => ReviewFilter::Readings->value,
            ])
            ->assertOk()
            ->assertJsonPath('data.answered', 2);

        $this->withToken($this->token)
            ->getJson("/api/v1/workspaces/{$this->workspace->id}/review?filter=" . ReviewFilter::Readings->value)
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_metadata_suggestions_can_be_read_without_being_applied()
    {
        $document = $this->documentAwaitingAReading();

        $this->withToken($this->token)
            ->getJson("/api/v1/documents/{$document->id}/metadata-suggestions")
            ->assertOk()
            ->assertJsonStructure(['data']);

        $this->assertSame([], $document->refresh()->metadata ?? []);
    }

    public function test_a_capture_session_hands_back_the_link_the_phone_acts_on()
    {
        $document = Document::factory()->for($this->workspace)->for($this->type)->create();

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/documents/{$document->id}/capture-sessions");

        $response->assertCreated()
            ->assertJsonPath('data.document_id', $document->id)
            ->assertJsonPath('data.is_active', true)
            ->assertJsonPath('data.replaces_attachment_id', null)
            // The signed URL itself, not a picture of it.
            ->assertJsonPath('data.pairing_url', fn (string $url): bool => str_contains($url, 'signature='));
    }

    public function test_a_session_can_be_aimed_at_replacing_one_attachment()
    {
        $document = Document::factory()->for($this->workspace)->for($this->type)->create();
        $attachment = app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->create('scan.pdf', 10),
            $this->admin,
        );

        $this->withToken($this->token)
            ->postJson("/api/v1/documents/{$document->id}/capture-sessions", ['attachment' => $attachment->id])
            ->assertCreated()
            ->assertJsonPath('data.replaces_attachment_id', $attachment->id);
    }

    public function test_an_attachment_from_another_document_cannot_be_targeted()
    {
        $document = Document::factory()->for($this->workspace)->for($this->type)->create();
        $other = Document::factory()->for($this->workspace)->for($this->type)->create();
        $theirs = app(UploadAttachment::class)->handle(
            $other,
            UploadedFile::fake()->create('other.pdf', 10),
            $this->admin,
        );

        $this->withToken($this->token)
            ->postJson("/api/v1/documents/{$document->id}/capture-sessions", ['attachment' => $theirs->id])
            ->assertStatus(422);
    }

    public function test_a_session_can_be_read_and_cancelled()
    {
        $document = Document::factory()->for($this->workspace)->for($this->type)->create();
        $id = $this->withToken($this->token)
            ->postJson("/api/v1/documents/{$document->id}/capture-sessions")
            ->json('data.id');

        $this->withToken($this->token)
            ->getJson("/api/v1/documents/{$document->id}/capture-sessions/{$id}")
            ->assertOk()
            ->assertJsonPath('data.is_active', true);

        $this->withToken($this->token)
            ->postJson("/api/v1/documents/{$document->id}/capture-sessions/{$id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.is_active', false);
    }

    public function test_a_session_reached_through_the_wrong_document_is_not_found()
    {
        $document = Document::factory()->for($this->workspace)->for($this->type)->create();
        $other = Document::factory()->for($this->workspace)->for($this->type)->create();

        $id = $this->withToken($this->token)
            ->postJson("/api/v1/documents/{$document->id}/capture-sessions")
            ->json('data.id');

        $this->withToken($this->token)
            ->getJson("/api/v1/documents/{$other->id}/capture-sessions/{$id}")
            ->assertNotFound();
    }
}
