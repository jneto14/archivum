<?php

declare(strict_types=1);

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateDocument;
use App\Actions\Documents\TrashAttachment;
use App\Actions\Documents\TrashDocument;
use App\Actions\Documents\UploadAttachment;
use App\Actions\Workspace\CalculateWorkspaceUsage;
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

/**
 * Covers the trash: what restoring brings back, what purging destroys, who may
 * do either, and the fact that a trashed item still occupies the disk it is
 * charged for.
 */
class TrashTest extends TestCase
{
    use RefreshDatabase;

    public function test_restoring_a_document_brings_back_the_attachments_trashed_with_it()
    {
        Storage::fake('local');
        [$workspace, $admin] = $this->workspace();
        $document = $this->document($workspace, $admin);
        $attachment = $this->attachment($document, $admin);

        app(TrashDocument::class)->handle($document);

        $this->actingAs($admin)
            ->post(route('trash.documents.restore', [$workspace, $document->id]))
            ->assertRedirect();

        $this->assertNotSoftDeleted('documents', ['id' => $document->id]);
        $this->assertNotSoftDeleted('document_attachments', ['id' => $attachment->id]);
    }

    public function test_restoring_a_document_leaves_an_attachment_deleted_beforehand_in_the_trash()
    {
        Storage::fake('local');
        [$workspace, $admin] = $this->workspace();
        $document = $this->document($workspace, $admin);
        $deletedEarlier = $this->attachment($document, $admin);
        $cascaded = $this->attachment($document, $admin);

        // Deleted on its own first, so it carries a different timestamp from
        // the one the document's deletion stamps across the rest.
        app(TrashAttachment::class)->handle($deletedEarlier);
        app(TrashDocument::class)->handle($document->fresh());

        $this->actingAs($admin)
            ->post(route('trash.documents.restore', [$workspace, $document->id]))
            ->assertRedirect();

        $this->assertNotSoftDeleted('document_attachments', ['id' => $cascaded->id]);
        $this->assertSoftDeleted('document_attachments', ['id' => $deletedEarlier->id]);
    }

    public function test_a_trashed_document_is_not_searchable_and_comes_back_on_restore()
    {
        [$workspace, $admin] = $this->workspace();
        $document = $this->document($workspace, $admin, 'Fatura da EDP');

        app(TrashDocument::class)->handle($document);
        $this->assertSame(0, Document::query()->where('workspace_id', $workspace->id)->count());

        app(\App\Actions\Documents\RestoreDocument::class)->handle($document->fresh());
        $this->assertSame(1, Document::query()->where('workspace_id', $workspace->id)->count());
    }

    public function test_the_trash_counts_towards_bytes_but_not_towards_the_item_counts()
    {
        Storage::fake('local');
        [$workspace, $admin] = $this->workspace();
        $document = $this->document($workspace, $admin);
        $attachment = $this->attachment($document, $admin);

        $usage = app(CalculateWorkspaceUsage::class);
        $before = $usage->storageBytes($workspace);
        $this->assertSame($attachment->size, $before);

        app(TrashDocument::class)->handle($document);
        $usage->forget($workspace);

        // The bytes are still on the disk, so the quota still knows about them.
        $this->assertSame($before, $usage->storageBytes($workspace));
        $this->assertSame($attachment->size, $usage->trashedStorageBytes($workspace));

        // The counts answer "what does this archive hold", and a trashed
        // document is not held — it is gone from every listing, from search
        // and from the sidebar badge, so counting it would contradict
        // everything on screen.
        $this->assertSame(0, $usage->documents($workspace));
        $this->assertSame(0, $usage->attachments($workspace));
        $this->assertSame(1, $usage->trashedDocuments($workspace));
    }

    public function test_a_trashed_document_does_not_block_filing_a_new_one()
    {
        [$workspace, $admin] = $this->workspace();
        $workspace->limits()->create(['documents' => 1]);
        $document = $this->document($workspace, $admin);

        app(TrashDocument::class)->handle($document);
        app(CalculateWorkspaceUsage::class)->forget($workspace);

        // The document limit counts what the archive holds. A deleted document
        // occupies bytes, which the storage limit charges for, but it does not
        // occupy a slot.
        $this->document($workspace, $admin, 'Filed after the deletion');

        $this->assertSame(1, app(CalculateWorkspaceUsage::class)->documents($workspace));
    }

    public function test_purging_a_document_destroys_it_and_unlinks_its_files()
    {
        Storage::fake('local');
        [$workspace, $admin] = $this->workspace();
        $document = $this->document($workspace, $admin);
        $attachment = $this->attachment($document, $admin);
        $path = $attachment->path;

        app(TrashDocument::class)->handle($document);

        $this->actingAs($admin)
            ->delete(route('trash.documents.purge', [$workspace, $document->id]))
            ->assertRedirect();

        $this->assertDatabaseMissing('documents', ['id' => $document->id]);
        $this->assertDatabaseMissing('document_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_emptying_the_trash_destroys_everything_in_it_and_nothing_else()
    {
        Storage::fake('local');
        [$workspace, $admin] = $this->workspace();
        $trashed = $this->document($workspace, $admin, 'Trashed');
        $kept = $this->document($workspace, $admin, 'Kept');

        app(TrashDocument::class)->handle($trashed);

        $this->actingAs($admin)
            ->delete(route('trash.empty', $workspace))
            ->assertRedirect();

        $this->assertDatabaseMissing('documents', ['id' => $trashed->id]);
        $this->assertDatabaseHas('documents', ['id' => $kept->id]);
    }

    public function test_a_member_who_could_delete_a_document_can_restore_it()
    {
        [$workspace] = $this->workspace();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User])->user;
        $document = $this->document($workspace, $member);

        app(TrashDocument::class)->handle($document);

        $this->actingAs($member)
            ->post(route('trash.documents.restore', [$workspace, $document->id]))
            ->assertRedirect();

        $this->assertNotSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_a_member_who_is_not_an_admin_cannot_purge_a_document()
    {
        [$workspace] = $this->workspace();
        $member = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::User])->user;
        $document = $this->document($workspace, $member);

        app(TrashDocument::class)->handle($document);

        // Trashing is reversible and purging is not, so the irreversible one
        // is narrower than the delete that put it there.
        $this->actingAs($member)
            ->delete(route('trash.documents.purge', [$workspace, $document->id]))
            ->assertForbidden();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_the_trash_of_another_workspace_is_not_reachable()
    {
        [$workspace, $admin] = $this->workspace();
        [$other] = $this->workspace();
        $document = $this->document($other, $admin);

        app(TrashDocument::class)->handle($document);

        $this->actingAs($admin)
            ->post(route('trash.documents.restore', [$workspace, $document->id]))
            ->assertNotFound();
    }

    public function test_the_prune_command_destroys_only_what_is_past_the_retention_window()
    {
        Storage::fake('local');
        config(['archivum.trash.retention_days' => 30]);
        [$workspace, $admin] = $this->workspace();
        $old = $this->document($workspace, $admin, 'Old');
        $recent = $this->document($workspace, $admin, 'Recent');

        app(TrashDocument::class)->handle($old);
        app(TrashDocument::class)->handle($recent);
        Document::withTrashed()->whereKey($old->id)->update(['deleted_at' => now()->subDays(31)]);

        $this->artisan('trash:prune')->assertSuccessful();

        $this->assertDatabaseMissing('documents', ['id' => $old->id]);
        $this->assertSoftDeleted('documents', ['id' => $recent->id]);
    }

    public function test_a_retention_of_zero_prunes_nothing()
    {
        config(['archivum.trash.retention_days' => 0]);
        [$workspace, $admin] = $this->workspace();
        $document = $this->document($workspace, $admin);

        app(TrashDocument::class)->handle($document);
        Document::withTrashed()->whereKey($document->id)->update(['deleted_at' => now()->subYears(5)]);

        $this->artisan('trash:prune')->assertSuccessful();

        $this->assertSoftDeleted('documents', ['id' => $document->id]);
    }

    public function test_the_trash_page_lists_what_it_holds()
    {
        Storage::fake('local');
        [$workspace, $admin] = $this->workspace();
        $document = $this->document($workspace, $admin, 'Trashed invoice');
        $standalone = $this->attachment($this->document($workspace, $admin, 'Live'), $admin);

        app(TrashDocument::class)->handle($document);
        app(TrashAttachment::class)->handle($standalone);

        $this->actingAs($admin)
            ->get(route('trash.index', $workspace))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('workspace/trash')
                ->where('documents.meta.total', 1)
                ->where('attachments.meta.total', 1)
                ->where('canPurge', true));
    }

    /**
     * A workspace and an admin belonging to it.
     *
     * @return array{0: Workspace, 1: User} The workspace and its admin.
     */
    private function workspace(): array
    {
        $workspace = Workspace::factory()->create();
        $admin = WorkspaceUser::factory()->for($workspace)->create(['role' => WorkspaceRole::Admin])->user;

        return [$workspace, $admin];
    }

    /**
     * @param Workspace $workspace The workspace to file it in.
     * @param User $creator The user filing it.
     * @param string $title The document's title.
     *
     * @return Document The created document.
     */
    private function document(Workspace $workspace, User $creator, string $title = 'Invoice'): Document
    {
        $type = DocumentType::factory()->for($workspace)->create();

        return app(CreateDocument::class)->handle($workspace, $creator, $type, $title, null, null);
    }

    /**
     * @param Document $document The document to attach it to.
     * @param User $uploader The user uploading it.
     *
     * @return DocumentAttachment The created attachment.
     */
    private function attachment(Document $document, User $uploader): DocumentAttachment
    {
        return app(UploadAttachment::class)->handle(
            $document,
            UploadedFile::fake()->create('scan.pdf', 100, 'application/pdf'),
            $uploader,
        );
    }
}
