<?php

declare(strict_types=1);

namespace Tests\Feature\Ocr;

use Tests\TestCase;

/**
 * Keeps the three copies of the text extraction toolchain in step.
 *
 * The packages are installed in three unrelated places — the development
 * image, the production image, and the CI runner — and nothing but attention
 * has kept them equal. Attention already failed once: ARC-84 added
 * `ext-imagick` to the images and not to CI, and `composer install` broke on
 * the runner, because `spatie/pdf-to-image` declares it as a platform
 * requirement.
 *
 * A shared package file was the obvious alternative and was rejected: the
 * development image builds from `./docker/8.5` as its context, so reaching a
 * file at the repository root would mean moving that context to `.` and
 * sending the whole repository to the daemon on every `sail build`. Drift is
 * the thing that actually hurts, so drift is what this test catches.
 */
class OcrToolchainParityTest extends TestCase
{
    /**
     * The binaries every environment must carry, and where each is declared.
     *
     * @var list<string>
     */
    private const PACKAGES = [
        'tesseract-ocr',
        'tesseract-ocr-eng',
        'tesseract-ocr-por',
        'poppler-utils',
        'ghostscript',
    ];

    /**
     * Where the toolchain is declared, by environment.
     *
     * @var array<string, string>
     */
    private const FILES = [
        'development image' => 'docker/8.5/Dockerfile',
        'production image' => 'docker/production/Dockerfile',
        'CI workflow' => '.github/workflows/tests.yml',
    ];

    public function test_every_environment_installs_the_whole_toolchain()
    {
        foreach (self::FILES as $environment => $path) {
            $contents = (string) file_get_contents(base_path($path));

            foreach (self::PACKAGES as $package) {
                $this->assertStringContainsString(
                    $package,
                    $contents,
                    "The {$environment} ({$path}) does not install {$package}. Text extraction fails wherever "
                    . 'it is missing, and it fails quietly — tesseract without a language pack exits '
                    . 'successfully and returns nothing at all.',
                );
            }
        }
    }

    /**
     * Every language in `archivum.ocr.languages` needs its own package, in
     * every environment. This is the failure with no symptom: tesseract
     * without the pack exits 0 and returns an empty string, which looks
     * exactly like a blank page.
     */
    public function test_every_configured_language_has_a_package_everywhere()
    {
        $languages = array_filter(explode('+', (string) config('archivum.ocr.languages')));

        $this->assertNotEmpty($languages, 'archivum.ocr.languages should name at least one language.');

        foreach (self::FILES as $environment => $path) {
            $contents = (string) file_get_contents(base_path($path));

            foreach ($languages as $language) {
                $this->assertStringContainsString(
                    "tesseract-ocr-{$language}",
                    $contents,
                    "The {$environment} ({$path}) is missing the pack for the configured language '{$language}'.",
                );
            }
        }
    }
}
