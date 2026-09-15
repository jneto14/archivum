<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Actions\Documents\ReplaceAttachmentFile;
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

class AttachmentVersionApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Document $document;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('archivum.attachments.disk'));

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin]);
        $this->user = $member->user;
        $type = DocumentType::factory()->for($workspace)->create();
        $this->document = Document::factory()->for($workspace)->for($type)->create();
        $this->token = $this->user->createToken('CLI')->plainTextToken;
    }

    /**
     * @return array{0: DocumentAttachment, 1: string} The attachment holding `rescan.pdf`, and the id of the superseded `scan.pdf`.
     */
    private function attachmentWithHistory(): array
    {
        $attachment = app(UploadAttachment::class)->handle(
            $this->document,
            UploadedFile::fake()->create('scan.pdf', 12),
            $this->user,
        );

        app(ReplaceAttachmentFile::class)->handle(
            $attachment,
            UploadedFile::fake()->create('rescan.pdf', 20),
            $this->user,
        );

        return [$attachment->refresh(), (string) $attachment->versions()->sole()->id];
    }

    public function test_the_files_an_attachment_used_to_hold_can_be_listed()
    {
        [$attachment, $versionId] = $this->attachmentWithHistory();

        $this->withToken($this->token)
            ->getJson("/api/v1/attachments/{$attachment->id}/versions")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $versionId)
            ->assertJsonPath('data.0.filename', 'scan.pdf')
            ->assertJsonPath('data.0.attachment_id', $attachment->id);
    }

    public function test_a_superseded_file_can_be_downloaded()
    {
        [, $versionId] = $this->attachmentWithHistory();

        $this->withToken($this->token)
            ->get("/api/v1/attachment-versions/{$versionId}/file")
            ->assertOk()
            ->assertDownload('scan.pdf');
    }

    /**
     * A swap, not an upload: what was current goes into the history behind the
     * file taking its place, so the chain keeps its length.
     */
    public function test_restoring_a_version_swaps_it_with_the_current_file()
    {
        [$attachment, $versionId] = $this->attachmentWithHistory();

        $response = $this->withToken($this->token)
            ->postJson("/api/v1/attachment-versions/{$versionId}/restore");

        $response->assertOk()
            ->assertJsonPath('data.filename', 'scan.pdf')
            ->assertJsonCount(1, 'data.versions')
            ->assertJsonPath('data.versions.0.filename', 'rescan.pdf');

        $this->assertSame('scan.pdf', $attachment->refresh()->filename);
    }

    public function test_a_history_in_another_workspace_is_out_of_reach()
    {
        $stranger = Workspace::factory()->create();
        $strangerMember = WorkspaceUser::factory()->for($stranger)->create(['role' => WorkspaceRole::Admin]);
        $strangerType = DocumentType::factory()->for($stranger)->create();
        $strangerDocument = Document::factory()->for($stranger)->for($strangerType)->create();

        $theirs = app(UploadAttachment::class)->handle(
            $strangerDocument,
            UploadedFile::fake()->create('theirs.pdf', 10),
            $strangerMember->user,
        );
        app(ReplaceAttachmentFile::class)->handle(
            $theirs,
            UploadedFile::fake()->create('theirs-again.pdf', 10),
            $strangerMember->user,
        );
        $theirVersionId = $theirs->versions()->sole()->id;

        $this->withToken($this->token)
            ->getJson("/api/v1/attachments/{$theirs->id}/versions")
            ->assertForbidden();

        $this->withToken($this->token)
            ->get("/api/v1/attachment-versions/{$theirVersionId}/file")
            ->assertForbidden();

        $this->withToken($this->token)
            ->postJson("/api/v1/attachment-versions/{$theirVersionId}/restore")
            ->assertForbidden();
    }
}
