<?php

declare(strict_types=1);

namespace App\Actions\Demo;

use App\Console\Commands\ResetDemo;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes every stored attachment and every generated export from the
 * attachments disk.
 *
 * Separate from {@see ResetDemo} so that the half of the
 * reset which touches files can be proven on its own, against a fake disk,
 * without a test having to run `migrate:fresh` on the suite's database.
 *
 * This is the half that is easy to forget and impossible to see. Attachments
 * live on a filesystem disk rather than in the database, so truncating tables
 * alone leaves every uploaded file behind — invisible to the application,
 * growing on the volume until it fills.
 */
class WipeDemoStorage
{
    /**
     * The top-level directories the application writes user files into.
     *
     * @var list<string>
     */
    public const DIRECTORIES = ['documents', 'exports'];

    /**
     * Remove both trees wholesale.
     *
     * Wholesale rather than record by record on purpose: the records are about
     * to cease to exist, and a file whose row was already lost — the exact
     * leak this exists to stop — would survive a sweep driven by the database.
     *
     * @return list<string> The directories that existed and were deleted, for the command to report.
     */
    public function handle(): array
    {
        $disk = Storage::disk((string) config('archivum.attachments.disk'));
        $cleared = [];

        foreach (self::DIRECTORIES as $directory) {
            if (!$disk->directoryExists($directory)) {
                continue;
            }

            $disk->deleteDirectory($directory);
            $cleared[] = $directory;
        }

        return $cleared;
    }
}
