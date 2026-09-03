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
    | Path Prefix
    |--------------------------------------------------------------------------
    |
    | The path an installation is served under, taken from APP_URL: '/archivum'
    | for https://example.com/archivum, and '' for an installation on its own
    | hostname. Derived rather than configured separately, so there is one
    | statement of where this installation lives and nothing to keep in step.
    |
    | The proxy in front is expected to strip this prefix before forwarding,
    | which is what a `proxy_pass` with a trailing slash does. Routes are
    | registered without it, so the application never sees it on the way in —
    | it only has to put it back on everything it hands out, including the URLs
    | compiled into the JavaScript bundle.
    |
    */

    'path_prefix' => mb_rtrim((string) parse_url((string) env('APP_URL', ''), PHP_URL_PATH), '/'),

    /*
    |--------------------------------------------------------------------------
    | Trusted Proxies
    |--------------------------------------------------------------------------
    |
    | Which proxies may be believed when they say, through X-Forwarded-*, what
    | the original request looked like. Nothing is trusted unless named here:
    | `*` for a container stack whose proxy address the network assigns, or a
    | comma-separated list of addresses.
    |
    | Set it only where the application cannot be reached around that proxy. A
    | directly exposed installation that trusts every proxy is trusting whatever
    | X-Forwarded-For a client cares to send, which hands anyone a fresh address
    | per attempt and walks them past the login throttle.
    |
    | Read here rather than in bootstrap/app.php, where it would look right and
    | be silently dead: the middleware closure there runs before the framework
    | loads .env, so the value would arrive only on installations that happen to
    | set it as a real process variable — and never on one with a cached config.
    |
    */

    'trusted_proxies' => env('TRUSTED_PROXIES', ''),

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

        /*
        | Seconds the whole extraction job may run, derived rather than fixed:
        | the worst case is every page of a scanned PDF taking the full
        | per-binary timeout, so anything below `max_pages * timeout` kills
        | work that was still progressing normally.
        |
        | Three numbers have to stay in order, or a long OCR runs twice at
        | once: the queue's `retry_after` must exceed the worker's `--timeout`,
        | which must be at least this. `QueueTimeoutTest` holds that invariant.
        */
        'job_timeout' => (int) env(
            'OCR_JOB_TIMEOUT',
            ((int) env('OCR_MAX_PAGES', 20) * (int) env('OCR_TIMEOUT', 120)) + 300,
        ),
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

    /*
    |--------------------------------------------------------------------------
    | Demo Mode
    |--------------------------------------------------------------------------
    |
    | Turns the installation into a public demo: a scheduled task wipes every
    | record and every uploaded file back to a seeded dataset once a day, so a
    | visitor can delete, rename and upload freely without leaving a mess for
    | the next one.
    |
    | Off unless BOTH of these are set, and the reset command refuses to run
    | otherwise no matter how it is invoked. Two variables rather than one
    | because the realistic accident is not a mistyped boolean — it is a
    | working .env copied from the demo onto a real installation.
    | DEMO_RESET_CONFIRM must repeat that installation's own APP_URL, so a
    | copied value stops matching the moment the URL changes and cannot travel
    | between hosts.
    |
    | Demo mode also blocks password changes, so the first visitor cannot lock
    | everyone else out until the next reset, and forces mail into the log
    | mailer so invitations and export links never reach a real inbox.
    |
    */

    'demo' => [
        'enabled' => (bool) env('DEMO_MODE', false),

        /** Must equal this installation's APP_URL for `demo:reset` to run. */
        'reset_confirm' => env('DEMO_RESET_CONFIRM'),

        /** 24-hour HH:MM, in the application timezone, shown in the banner. */
        'reset_at' => env('DEMO_RESET_AT', '04:00'),

        /** Credentials the login screen offers, since a demo has nobody to ask. */
        'email' => env('DEMO_EMAIL', 'demo@archivum.example'),
        'password' => env('DEMO_PASSWORD', 'demo1234'),
    ],

];
