<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\PurgeAttachment;
use App\Actions\Documents\PurgeDocument;
use App\Actions\Documents\TrashAttachment;
use App\Actions\Documents\TrashDocument;
use App\Actions\Documents\UploadAttachment;
use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Enums\OcrStatus;
use App\Enums\WorkspaceRole;
use App\Jobs\ExtractAttachmentText;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLimit;
use App\Models\WorkspaceUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AttachmentVersionTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A workspace with one member and one document holding one attachment,
     * which is the starting point every test here shares.
     *
     * @param WorkspaceRole $role The member's role in the workspace.
     *
     * @return array{Workspace, User, Document, DocumentAttachment}
     */
    private function archive(WorkspaceRole $role = WorkspaceRole::User): array
    {
        Storage::fake('local');

        $workspace = Workspace::factory()->create();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => $role])->user;
        $type = DocumentType::factory()->for($workspace)->create();
        $document = app(CreateDocument::class)->handle($workspace, $member, $type, 'Invoice', null, null);
        $attachment = app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->create('first.pdf', 10, 'application/pdf'),
            $member,
        );

        return [$workspace, $member, $document, $attachment];
    }

    public function test_replacing_a_file_keeps_the_one_it_replaced()
    {
        [, $member, , $attachment] = $this->archive();

        $originalPath = $attachment->path;

        $this->actingAs($member)
            ->post(route('attachments.file.replace', $attachment), [
                'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
            ])
            ->assertRedirect();

        $attachment->refresh();

        $this->assertSame('better.pdf', $attachment->filename);
        $this->assertNotSame($originalPath, $attachment->path);
        Storage::disk('local')->assertExists($attachment->path);

        $version = $attachment->versions()->sole();

        $this->assertSame('first.pdf', $version->filename);
        $this->assertSame($originalPath, $version->path);
        Storage::disk('local')->assertExists($version->path);
    }

    public function test_replacing_records_who_replaced_it_and_when_each_file_arrived()
    {
        [$workspace, $member, , $attachment] = $this->archive();

        $other = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User])->user;
        $uploadedAt = $attachment->fileUploadedAt();

        $this->travel(1)->hours();

        $this->actingAs($other)->post(route('attachments.file.replace', $attachment), [
            'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
        ]);

        $attachment->refresh();
        $version = $attachment->versions()->sole();

        $this->assertSame($other->id, $attachment->uploaded_by);
        $this->assertTrue($attachment->fileUploadedAt()->isAfter($uploadedAt));

        // The superseded row keeps the original uploader and the moment its
        // file arrived, which is the whole point of the history.
        $this->assertSame($member->id, $version->uploaded_by);
        $this->assertSame($uploadedAt->toIso8601String(), $version->uploaded_at->toIso8601String());
    }

    public function test_replacing_reads_the_new_file_and_stops_quoting_the_old_one()
    {
        Bus::fake([ExtractAttachmentText::class]);

        [, $member, $document, $attachment] = $this->archive();

        $attachment->markOcrCompleted('words from the first scan', 5, 5);
        $document->refreshOcrText();

        $this->assertStringContainsString('first scan', (string) $document->fresh()->ocr_text);

        $this->actingAs($member)->post(route('attachments.file.replace', $attachment), [
            'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
        ]);

        // The mirror is rebuilt immediately rather than when the new reading
        // lands: until then the archive would be findable by a scan nobody
        // can open any more.
        $this->assertNull($document->fresh()->ocr_text);
        $this->assertSame(OcrStatus::Processing, $attachment->fresh()->ocr_status);

        Bus::assertDispatched(
            ExtractAttachmentText::class,
            fn (ExtractAttachmentText $job) => $job->attachment->is($attachment),
        );
    }

    public function test_a_superseded_file_keeps_no_text_of_its_own()
    {
        Bus::fake([ExtractAttachmentText::class]);

        [, $member, , $attachment] = $this->archive();

        $attachment->markOcrCompleted('words from the first scan', 5, 5);
        $attachment->recordTextFingerprint(1234);

        $this->actingAs($member)->post(route('attachments.file.replace', $attachment), [
            'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
        ]);

        $attachment->refresh();

        // Nothing derived from the old reading survives: keeping the
        // fingerprint would have the next reading report itself a duplicate
        // of the page it replaced.
        $this->assertNull($attachment->ocr_text);
        $this->assertNull($attachment->text_simhash);
        $this->assertNull($attachment->duplicate_of_attachment_id);
    }

    public function test_a_replacement_that_would_pass_the_storage_limit_is_refused()
    {
        [$workspace, $member, , $attachment] = $this->archive();

        // Room for the 10KB already stored and a little more, but not for
        // another 20KB on top — the file being replaced stays on disk.
        WorkspaceLimit::factory()->for($workspace)->create(['storage_bytes' => 15 * 1024]);

        $this->actingAs($member)
            ->post(route('attachments.file.replace', $attachment), [
                'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasErrors('file');

        $this->assertSame('first.pdf', $attachment->fresh()->filename);
        $this->assertSame(0, $attachment->versions()->count());
    }

    public function test_a_workspace_at_its_attachment_limit_can_still_replace_a_file()
    {
        [$workspace, $member, , $attachment] = $this->archive();

        WorkspaceLimit::factory()->for($workspace)->create(['attachments' => 1]);

        $this->actingAs($member)
            ->post(route('attachments.file.replace', $attachment), [
                'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('better.pdf', $attachment->fresh()->filename);
        $this->assertSame(1, app(CalculateWorkspaceUsage::class)->attachments($workspace));
    }

    public function test_a_superseded_file_still_counts_against_the_storage_total()
    {
        [$workspace, $member, , $attachment] = $this->archive();

        $this->actingAs($member)->post(route('attachments.file.replace', $attachment), [
            'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
        ]);

        $usage = app(CalculateWorkspaceUsage::class);
        $usage->forget($workspace);

        // Both files are on the disk, so both are charged for.
        $this->assertSame(
            $attachment->fresh()->size + $attachment->versions()->sum('size'),
            $usage->storageBytes($workspace),
        );
    }

    public function test_trashing_an_attachment_counts_its_versions_as_recoverable()
    {
        [$workspace, $member, , $attachment] = $this->archive();

        $this->actingAs($member)->post(route('attachments.file.replace', $attachment), [
            'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
        ]);

        app(TrashAttachment::class)->handle($attachment->fresh());

        $usage = app(CalculateWorkspaceUsage::class);
        $usage->forget($workspace);

        // Purging the attachment unlinks its whole chain, so every byte of it
        // is recoverable and not just the file it was holding.
        $expected = $attachment->fresh()->size + $attachment->versions()->sum('size');

        $this->assertSame($expected, $usage->trashedStorageBytes($workspace));
        $this->assertSame($expected, $usage->storageBytes($workspace));
    }

    public function test_an_earlier_version_can_be_downloaded()
    {
        [, $member, , $attachment] = $this->archive();

        $this->actingAs($member)->post(route('attachments.file.replace', $attachment), [
            'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
        ]);

        $version = $attachment->versions()->sole();

        $this->actingAs($member)
            ->get(route('attachment-versions.show', $version))
            ->assertOk()
            ->assertDownload('first.pdf');
    }

    public function test_restoring_a_version_swaps_it_back_and_files_the_other_one()
    {
        [, $member, , $attachment] = $this->archive();

        $originalPath = $attachment->path;

        $this->actingAs($member)->post(route('attachments.file.replace', $attachment), [
            'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
        ]);

        $attachment->refresh();
        $replacementPath = $attachment->path;
        $version = $attachment->versions()->sole();

        $this->actingAs($member)
            ->post(route('attachment-versions.restore', $version))
            ->assertRedirect();

        $attachment->refresh();

        $this->assertSame('first.pdf', $attachment->filename);
        $this->assertSame($originalPath, $attachment->path);

        // The history holds the file it displaced, and no longer holds the one
        // that came back — two rows pointing at one path is what the purge
        // would get wrong.
        $restoredInto = $attachment->versions()->sole();
        $this->assertSame('better.pdf', $restoredInto->filename);
        $this->assertSame($replacementPath, $restoredInto->path);
        $this->assertSame($member->id, $restoredInto->uploaded_by);
    }

    public function test_restoring_reads_the_file_again_rather_than_recovering_its_old_text()
    {
        Bus::fake([ExtractAttachmentText::class]);

        [, $member, , $attachment] = $this->archive();

        $this->actingAs($member)->post(route('attachments.file.replace', $attachment), [
            'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
        ]);

        $attachment->fresh()->markOcrCompleted('words from the replacement', 4, 4);

        $this->actingAs($member)->post(
            route('attachment-versions.restore', $attachment->versions()->sole()),
        );

        $attachment->refresh();

        $this->assertNull($attachment->ocr_text);
        $this->assertSame(OcrStatus::Processing, $attachment->ocr_status);
        Bus::assertDispatchedTimes(ExtractAttachmentText::class, 3);
    }

    public function test_purging_an_attachment_unlinks_every_file_it_ever_held()
    {
        [, $member, , $attachment] = $this->archive();

        $originalPath = $attachment->path;

        $this->actingAs($member)->post(route('attachments.file.replace', $attachment), [
            'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
        ]);

        $attachment->refresh();
        $replacementPath = $attachment->path;

        app(TrashAttachment::class)->handle($attachment);
        app(PurgeAttachment::class)->handle($attachment->fresh());

        Storage::disk('local')->assertMissing($originalPath);
        Storage::disk('local')->assertMissing($replacementPath);
        $this->assertDatabaseCount('document_attachment_versions', 0);
    }

    public function test_purging_a_document_unlinks_its_attachments_version_files()
    {
        [, $member, $document, $attachment] = $this->archive();

        $originalPath = $attachment->path;

        $this->actingAs($member)->post(route('attachments.file.replace', $attachment), [
            'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
        ]);

        app(TrashDocument::class)->handle($document);
        app(PurgeDocument::class)->handle($document->fresh());

        Storage::disk('local')->assertMissing($originalPath);
        $this->assertDatabaseCount('document_attachment_versions', 0);
    }

    public function test_an_outsider_can_neither_replace_a_file_nor_reach_its_history()
    {
        [, $member, , $attachment] = $this->archive();

        $this->actingAs($member)->post(route('attachments.file.replace', $attachment), [
            'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
        ]);

        $version = $attachment->versions()->sole();
        $outsider = WorkspaceUser::factory()->create()->user;

        $this->actingAs($outsider)
            ->post(route('attachments.file.replace', $attachment), [
                'file' => UploadedFile::fake()->create('theirs.pdf', 10, 'application/pdf'),
            ])
            ->assertForbidden();

        $this->actingAs($outsider)->get(route('attachment-versions.show', $version))->assertForbidden();
        $this->actingAs($outsider)->post(route('attachment-versions.restore', $version))->assertForbidden();
    }

    public function test_a_member_who_did_not_upload_the_file_may_still_replace_it()
    {
        [$workspace, , , $attachment] = $this->archive();

        $other = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User])->user;

        // Deliberately wider than deleting, which this member may not do:
        // replacing loses nothing, and is undone by restoring the version it
        // just created.
        $this->actingAs($other)
            ->delete(route('attachments.destroy', $attachment))
            ->assertForbidden();

        $this->actingAs($other)
            ->post(route('attachments.file.replace', $attachment), [
                'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('better.pdf', $attachment->fresh()->filename);
    }

    public function test_the_document_page_ships_the_version_history()
    {
        [, $member, $document, $attachment] = $this->archive();

        $this->actingAs($member)->post(route('attachments.file.replace', $attachment), [
            'file' => UploadedFile::fake()->create('better.pdf', 20, 'application/pdf'),
        ]);

        $this->actingAs($member)
            ->get(route('documents.show', $document))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('document.attachments.0.filename', 'better.pdf')
                ->has('document.attachments.0.versions', 1)
                ->where('document.attachments.0.versions.0.filename', 'first.pdf')
                ->where('document.attachments.0.versions.0.uploader.name', $member->name)
                ->etc());
    }
}
