<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\OpenApiSpec;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Writes the committed snapshot of the OpenAPI document.
 *
 * Nothing at runtime needs this. `GET /api/v1/openapi.json` builds the spec
 * from the route table on every request, so a client always gets a description
 * of the application that is running and never one that went stale because
 * somebody forgot a step.
 *
 * `docs/openapi.json` exists for review rather than for serving: a change to
 * the API contract then shows up in a diff, next to the routes that caused it.
 * `--check` is what keeps that copy honest — it fails when the committed file
 * is not what OpenApiSpec would produce now, and it runs inside the test suite
 * so a route added without regenerating fails CI.
 */
#[Signature('api:openapi {--check : Fail when the committed snapshot is out of date rather than rewriting it}')]
#[Description('Refresh docs/openapi.json, the committed snapshot of the API spec')]
class GenerateOpenApiSpec extends Command
{
    /** Where the snapshot lives, relative to the project root. */
    public const PATH = 'docs/openapi.json';

    /**
     * @param OpenApiSpec $spec Builds the document from the route table.
     *
     * @return int 0 when the snapshot was written, or is current under `--check`; 1 when `--check` finds it stale.
     */
    public function handle(OpenApiSpec $spec): int
    {
        $json = json_encode(
            $spec->build(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) . "\n";

        $path = base_path(self::PATH);

        if ($this->option('check')) {
            if (File::exists($path) && File::get($path) === $json) {
                $this->info(self::PATH . ' is up to date.');

                return self::SUCCESS;
            }

            $this->error(self::PATH . ' is out of date. Run `php artisan api:openapi`.');

            return self::FAILURE;
        }

        File::put($path, $json);

        $this->info('Wrote ' . self::PATH . '.');

        return self::SUCCESS;
    }
}
