<?php

declare(strict_types=1);

namespace App\Services\Ocr;

use RuntimeException;

/**
 * The attachment's own bytes cannot be parsed — a truncated upload, or a file
 * whose recorded mime type does not match what it actually contains.
 *
 * Distinct from every other extraction failure because it is permanent: the
 * file will not become readable on a second attempt. `ExtractAttachmentText`
 * therefore records it on the attachment and stops, rather than retrying and
 * eventually landing in `failed_jobs`.
 *
 * It also means a corrupt upload never breaks the upload itself. That matters
 * on installations running the `sync` queue driver, where the extraction job
 * executes inside the HTTP request that uploaded the file.
 */
class UnreadableAttachment extends RuntimeException {}
