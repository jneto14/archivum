<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Initial Admin User
    |--------------------------------------------------------------------------
    |
    | Used by the database seeder to create the first user on a fresh
    | self-hosted installation. If ADMIN_PASSWORD is left empty, the seeder
    | generates a random password and prints it once during seeding.
    |
    */

    'admin' => [
        'name' => env('ADMIN_NAME', 'Admin'),
        'email' => env('ADMIN_EMAIL', 'admin@example.com'),
        'password' => env('ADMIN_PASSWORD'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Multi-Workspace
    |--------------------------------------------------------------------------
    |
    | When disabled, the installation transparently uses a single default
    | Workspace (seeded on first install) instead of exposing workspace
    | creation/switching. The Workspace model and tables still exist either
    | way.
    |
    */

    'multi_workspace_enabled' => env('MULTI_WORKSPACE_ENABLED', true),

    'default_workspace_name' => env('DEFAULT_WORKSPACE_NAME', 'Default Workspace'),

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    |
    | Disk used to store Document attachments (scans, photos, PDFs). Defaults
    | to the private "local" disk rather than "public", since documents may
    | contain sensitive content. Set to "s3" (pointed at AWS S3 or a
    | MinIO-compatible endpoint via the AWS_* env vars) for non-local
    | deployments.
    |
    */

    'attachments' => [
        'disk' => env('ATTACHMENTS_DISK', 'local'),

        /*
        |----------------------------------------------------------------------
        | Export Retention
        |----------------------------------------------------------------------
        |
        | Number of days a generated document-export CSV is kept on disk
        | before the scheduled prune command deletes it. The signed download
        | link emailed to the user who triggered the export expires after
        | the same number of days, so the link and the file go stale together.
        |
        */

        'export_retention_days' => (int) env('EXPORT_RETENTION_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachment Text Extraction
    |--------------------------------------------------------------------------
    |
    | After an attachment is uploaded, a queued job extracts its text so the
    | document can be found by what is written on the page, not just by its
    | title.
    |
    | Two paths, because they are not interchangeable. A PDF that was born
    | digital already carries a text layer: reading it is instant and exact,
    | and running OCR over it instead would be slower and less accurate. A
    | scan or a photo has no text at all, and only OCR can recover it. So the
    | text layer is tried first and OCR is the fallback.
    |
    | This depends on system binaries that are NOT PHP extensions:
    | `tesseract` (plus a language pack per configured language) and
    | `pdftotext` from poppler-utils, with Ghostscript behind Imagick for
    | rasterizing scanned PDFs. They ship in this project's Docker image; on
    | an installation without them, extraction records itself as unavailable
    | on the attachment rather than failing the upload. Set `enabled` to false
    | to turn the whole thing off deliberately.
    |
    */

    'ocr' => [
        'enabled' => (bool) env('OCR_ENABLED', true),

        /*
        | Tesseract language codes, joined with "+". Each one needs its
        | matching `tesseract-ocr-<code>` package installed, or Tesseract
        | recognises nothing. Order matters: put the language most documents
        | are in first.
        */
        'languages' => env('OCR_LANGUAGES', 'por+eng'),

        /*
        | How many characters a PDF's embedded text layer must yield before
        | it's taken at face value. Below this the PDF is treated as a scan
        | and sent to OCR — scanned PDFs often carry a few stray characters
        | from a header or a stamp, which is not a text layer.
        */
        'min_text_length' => (int) env('OCR_MIN_TEXT_LENGTH', 100),

        /*
        | Ceiling on pages rasterized and OCR'd per attachment. OCR costs
        | roughly a second of CPU per page, so a 300-page scan would otherwise
        | occupy a queue worker for five minutes.
        */
        'max_pages' => (int) env('OCR_MAX_PAGES', 20),

        /*
        | Rasterization resolution. Tesseract is trained around 300 DPI and
        | degrades noticeably below it; going higher mostly costs time.
        */
        'dpi' => (int) env('OCR_DPI', 300),

        /*
        | Seconds any single binary call may run before it's killed, so one
        | pathological file cannot hang a worker indefinitely.
        */
        'timeout' => (int) env('OCR_TIMEOUT', 120),
    ],

    /*
    |--------------------------------------------------------------------------
    | Locales
    |--------------------------------------------------------------------------
    |
    | Languages users may pick as their preferred locale, keyed by IETF
    | language tag. The application's default locale (config('app.locale'))
    | is used whenever a user hasn't chosen one.
    |
    */

    'locales' => [
        'en' => 'English',
        'pt' => 'Português',
    ],

];
