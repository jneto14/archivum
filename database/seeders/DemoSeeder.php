<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\NodeValueStrategy;
use App\Enums\WorkspaceRole;
use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\DocumentLocation;
use App\Models\DocumentType;
use App\Models\OrganizationLevel;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLimit;
use App\Models\WorkspaceUser;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

/**
 * The dataset a demo installation wakes up to every morning.
 *
 * Deliberately not `DatabaseSeeder`. That one bootstraps a real installation —
 * one admin and an empty workspace — which is the right thing to hand someone
 * who is about to file their own archive, and the wrong thing to show a
 * visitor who has two minutes: an empty archive demonstrates nothing.
 *
 * So this seeds an archive that is already in use — a filing scheme with real
 * shelves, documents sitting in them, tags, and attachments whose text has been
 * extracted, so search returns something on the first query rather than after
 * the visitor has uploaded a file and waited for a queue worker.
 */
class DemoSeeder extends Seeder
{
    use WithoutModelEvents;

    /** Where the committed sample PDFs live, relative to this file. */
    private const FILES = __DIR__ . '/demo-files';

    public function run(): void
    {
        $user = $this->seedUser();
        $workspace = $this->seedWorkspace($user);
        $nodes = $this->seedOrganizationScheme($workspace);
        $types = $this->seedDocumentTypes($workspace);

        $this->seedTags($workspace);
        $this->seedDocuments($workspace, $user, $nodes, $types);

        $this->command->info('Demo dataset seeded.');
        $this->command->info(sprintf(
            'Sign in with %s / %s',
            config('archivum.demo.email'),
            config('archivum.demo.password'),
        ));
    }

    /**
     * The one account the demo offers, with the credentials the login screen
     * prints. Email is pre-verified: a demo has no inbox to check.
     */
    private function seedUser(): User
    {
        $user = User::query()->create([
            'name' => 'Demo',
            'email' => (string) config('archivum.demo.email'),
            'password' => Hash::make((string) config('archivum.demo.password')),
            'email_verified_at' => now(),
        ]);

        $user->is_platform_admin = true;
        $user->save();

        return $user;
    }

    /**
     * Limits are set rather than left open on purpose. Anyone can log into a
     * demo, and an upload spree between two resets would otherwise fill the
     * volume — the reset would clean it up, but only after the disk was
     * already full. These are generous enough to explore and far too small to
     * be worth abusing.
     */
    private function seedWorkspace(User $user): Workspace
    {
        $workspace = Workspace::query()->create(['name' => 'City Archive']);

        WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => WorkspaceRole::Admin,
        ]);

        WorkspaceLimit::query()->create([
            'workspace_id' => $workspace->id,
            'storage_bytes' => 256 * 1024 * 1024,
            'users' => 5,
            'documents' => 200,
            'attachments' => 200,
        ]);

        return $workspace;
    }

    /**
     * A three-level physical scheme — room, cabinet, shelf — because the split
     * between where a document *is* and how it is *found* is the thing this
     * application is about, and one level does not show it.
     *
     * @return list<OrganizationNode> The leaf shelves, in filing order.
     */
    private function seedOrganizationScheme(Workspace $workspace): array
    {
        $scheme = OrganizationScheme::query()->create([
            'workspace_id' => $workspace->id,
            'name' => 'Repository',
        ]);

        $levels = [];

        foreach ([
            ['name' => 'Room', 'key' => 'room', 'strategy' => NodeValueStrategy::Manual, 'capacity' => null],
            ['name' => 'Cabinet', 'key' => 'cabinet', 'strategy' => NodeValueStrategy::Alphabetical, 'capacity' => 4],
            ['name' => 'Shelf', 'key' => 'shelf', 'strategy' => NodeValueStrategy::Sequential, 'capacity' => 6],
        ] as $position => $level) {
            $levels[] = OrganizationLevel::query()->create([
                'scheme_id' => $scheme->id,
                'name' => $level['name'],
                'key' => $level['key'],
                'position' => $position,
                'capacity' => $level['capacity'],
                'value_strategy' => $level['strategy'],
            ]);
        }

        $room = OrganizationNode::query()->create([
            'level_id' => $levels[0]->id,
            'parent_id' => null,
            'value' => 'Floor 1',
        ]);

        $shelves = [];

        foreach (['A', 'B'] as $cabinetValue) {
            $cabinet = OrganizationNode::query()->create([
                'level_id' => $levels[1]->id,
                'parent_id' => $room->id,
                'value' => $cabinetValue,
            ]);

            foreach (['001', '002'] as $shelfValue) {
                $shelves[] = OrganizationNode::query()->create([
                    'level_id' => $levels[2]->id,
                    'parent_id' => $cabinet->id,
                    'value' => $shelfValue,
                ]);
            }
        }

        return $shelves;
    }

    /**
     * @return array<string, DocumentType> Types keyed by their key.
     */
    private function seedDocumentTypes(Workspace $workspace): array
    {
        $types = [];

        foreach ([
            'invoice' => 'Invoice',
            'agreement' => 'Agreement',
            'certificate' => 'Certificate',
            'letter' => 'Letter',
        ] as $key => $name) {
            $types[$key] = DocumentType::query()->create([
                'workspace_id' => $workspace->id,
                'name' => $name,
                'key' => $key,
            ]);
        }

        return $types;
    }

    private function seedTags(Workspace $workspace): void
    {
        foreach (['2026', 'urgent', 'paid', 'tenancy', 'property'] as $name) {
            Tag::query()->create([
                'workspace_id' => $workspace->id,
                'name' => $name,
            ]);
        }
    }

    /**
     * @param list<OrganizationNode> $shelves
     * @param array<string, DocumentType> $types
     */
    private function seedDocuments(
        Workspace $workspace,
        User $user,
        array $shelves,
        array $types,
    ): void {
        $documents = [
            [
                'title' => 'Invoice 2026/0184 — Northgate Stationery',
                'type' => 'invoice',
                'date' => '2026-03-14',
                'tags' => ['2026', 'paid'],
                'file' => 'invoice-2026-0184.pdf',
            ],
            [
                'title' => 'Tenancy agreement — 42 Almond Road',
                'type' => 'agreement',
                'date' => '2026-01-01',
                'tags' => ['tenancy', '2026'],
                'file' => 'tenancy-agreement.pdf',
            ],
            [
                'title' => 'Land registry certificate — Ashfield 3921',
                'type' => 'certificate',
                'date' => '2026-02-02',
                'tags' => ['property'],
                'file' => 'land-registry-certificate.pdf',
            ],
            [
                'title' => 'Letter 44/2026 — City Council',
                'type' => 'letter',
                'date' => '2026-02-19',
                'tags' => ['2026', 'urgent'],
                'file' => null,
            ],
            [
                'title' => 'Minutes of the meeting of 8 January',
                'type' => 'letter',
                'date' => '2026-01-08',
                'tags' => [],
                'file' => null,
            ],
        ];

        foreach ($documents as $index => $data) {
            $document = Document::query()->create([
                'workspace_id' => $workspace->id,
                'document_type_id' => $types[$data['type']]->id,
                'created_by' => $user->id,
                'title' => $data['title'],
                'document_date' => $data['date'],
            ]);

            DocumentLocation::query()->create([
                'document_id' => $document->id,
                'organization_node_id' => $shelves[$index % count($shelves)]->id,
            ]);

            // Looked up rather than carried in a keyed array: a tag named
            // "2026" would arrive as an integer key in PHP, which is exactly
            // the kind of quiet type drift a seeder should not invent.
            $document->tags()->attach(
                Tag::query()
                    ->where('workspace_id', $workspace->id)
                    ->whereIn('name', $data['tags'])
                    ->pluck('id')
                    ->all(),
            );

            if ($data['file'] !== null) {
                $this->attach($document, $user, $data['file']);
            }
        }
    }

    /**
     * Copy a committed sample PDF onto the attachments disk and record it as
     * already extracted.
     *
     * The text is pulled from the file here rather than dispatched to
     * `ExtractAttachmentText`, because a reset that leaves search empty until a
     * queue worker catches up is a demo that looks broken for its first few
     * minutes. Extraction is exercised for real the moment a visitor uploads
     * anything of their own.
     */
    private function attach(Document $document, User $user, string $filename): void
    {
        $source = self::FILES . '/' . $filename;
        $disk = (string) config('archivum.attachments.disk');
        $path = "documents/{$document->id}/{$filename}";

        Storage::disk($disk)->put($path, (string) file_get_contents($source));

        $attachment = DocumentAttachment::query()->create([
            'document_id' => $document->id,
            'uploaded_by' => $user->id,
            'disk' => $disk,
            'path' => $path,
            'filename' => $filename,
            'mime_type' => 'application/pdf',
            'size' => (int) filesize($source),
            'checksum' => hash_file('sha256', $source),
        ]);

        $attachment->markOcrCompleted($this->extractText($source));
    }

    /**
     * Read the sample's text layer, falling back to its title if the toolchain
     * is not present. Only the demo dataset depends on this, so a missing
     * `pdftotext` should degrade the demo rather than fail the reset.
     */
    private function extractText(string $source): string
    {
        $output = [];
        $status = 0;

        exec(sprintf('pdftotext %s - 2>/dev/null', escapeshellarg($source)), $output, $status);

        return $status === 0 && $output !== []
            ? implode("\n", $output)
            : basename($source, '.pdf');
    }
}
