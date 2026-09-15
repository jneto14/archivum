<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Support\Str;

/**
 * Writes docs/openapi.json from the application's own route table.
 *
 * Built rather than hand-written, and built from `Router::getRoutes()` rather
 * than from a list kept alongside it, so the spec cannot describe a route that
 * does not exist or miss one that does. `--check` is what holds that: it fails
 * when the committed file is not what this command would produce, so a route
 * added without regenerating fails CI instead of quietly going undocumented.
 *
 * No spec-generation package. One would read the controllers and the Form
 * Requests, which sounds like less work until a Form Request scopes its rules
 * by reading the route — several here do — and the reflection needed to
 * evaluate that is more fragile than saying the shapes out loud.
 *
 * What is derived and what is stated is deliberate: paths, methods, path
 * parameters, tags and security come from the routes and are therefore always
 * right. Summaries, request bodies and response schemas are stated in
 * `catalogue()`, reviewed like any other code, and the operation for a route
 * missing from it is still emitted — with the generic shapes — so a gap shows
 * up as a thin entry rather than as a missing endpoint.
 */
#[Signature('api:openapi {--check : Fail when the committed spec is out of date rather than rewriting it}')]
#[Description('Generate docs/openapi.json from the /api/v1 route table')]
class GenerateOpenApiSpec extends Command
{
    /** Where the spec is written, relative to the project root. */
    public const PATH = 'docs/openapi.json';

    /**
     * Only these routes are described. The unversioned `/api/user` predates the
     * versioned API and is deliberately left out: it is kept working for
     * whatever may still call it, not offered as something to build against.
     */
    private const ROUTE_PREFIX = 'api.v1.';

    /**
     * @return int 0 when the spec was written, or is current under `--check`; 1 when `--check` finds it stale.
     */
    public function handle(): int
    {
        $json = json_encode(
            $this->build(),
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

    /**
     * @return array<string, mixed> The whole OpenAPI document.
     */
    private function build(): array
    {
        return [
            'openapi' => '3.1.0',
            'info' => [
                'title' => 'Archivum API',
                'version' => '1.0.0',
                'description' => 'The token-authenticated HTTP API. See docs/api.md for the reasoning behind these shapes.',
            ],
            // A variable rather than a baked-in URL: this file is committed to
            // a repository every installation deploys from its own host, and
            // reading APP_URL at generation time would write whichever machine
            // ran the command into everybody's spec.
            'servers' => [[
                'url' => '{origin}/api/v1',
                'variables' => ['origin' => [
                    'default' => 'https://archivum.example',
                    'description' => "The installation's own origin, including any path prefix it is served under.",
                ]],
            ]],
            'security' => [['bearerAuth' => []]],
            'tags' => $this->tags(),
            'paths' => $this->paths(),
            'components' => [
                'securitySchemes' => ['bearerAuth' => [
                    'type' => 'http',
                    'scheme' => 'bearer',
                    'description' => 'A personal access token from Settings → API tokens.',
                ]],
                'schemas' => $this->schemas(),
                'responses' => $this->sharedResponses(),
                'parameters' => $this->sharedParameters(),
            ],
        ];
    }

    /**
     * @return array<int, array{name: string, description: string}> The groups operations are filed under.
     */
    private function tags(): array
    {
        return [
            ['name' => 'Identity', 'description' => 'Who a token belongs to.'],
            ['name' => 'Workspaces', 'description' => 'Workspaces, their usage, their limits and their members.'],
            ['name' => 'Documents', 'description' => 'Registering, finding and filing documents.'],
            ['name' => 'Vocabulary', 'description' => 'The document types and tags a workspace files by.'],
            ['name' => 'Attachments', 'description' => 'Scans, their files, their readings and their history.'],
            ['name' => 'Trash', 'description' => 'What was deleted, and the two ways out of it.'],
            ['name' => 'Organization', 'description' => 'How the physical archive is laid out.'],
            ['name' => 'Intake', 'description' => 'What the archive worked out on its own, and the answers to it.'],
            ['name' => 'Tasks', 'description' => 'Background work, and the audit trail.'],
        ];
    }

    /**
     * Walk the route table and turn every versioned route into an operation.
     *
     * @return array<string, array<string, mixed>> Paths, each holding its methods.
     */
    private function paths(): array
    {
        $catalogue = $this->catalogue();
        $paths = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (!str_starts_with($name, self::ROUTE_PREFIX)) {
                continue;
            }

            $key = Str::after($name, self::ROUTE_PREFIX);
            $path = '/' . Str::after($route->uri(), 'api/v1/');

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $paths[$path][mb_strtolower($method)] = $this->operation(
                    $route,
                    $key,
                    $catalogue[$key] ?? [],
                );
            }
        }

        ksort($paths);

        return $paths;
    }

    /**
     * @param Route $route The route being described.
     * @param string $key The route's name with the version prefix stripped, which is also its operationId.
     * @param array<string, mixed> $entry What `catalogue()` says about it, or an empty array when it says nothing.
     *
     * @return array<string, mixed> One OpenAPI operation.
     */
    private function operation(Route $route, string $key, array $entry): array
    {
        $operation = [
            'operationId' => $key,
            'tags' => [$entry['tag'] ?? 'Documents'],
            'summary' => $entry['summary'] ?? $key,
            'parameters' => $this->parameters($route, $entry),
            'responses' => $this->responses($entry),
        ];

        // Only one operation is public, and it says so rather than inheriting
        // the document-level requirement: a client reading the spec to find
        // out how to authenticate has no token yet.
        if (($entry['public'] ?? false) === true) {
            $operation['security'] = [];
        }

        if (isset($entry['request'])) {
            $operation['requestBody'] = [
                'required' => true,
                'content' => [
                    ($entry['multipart'] ?? false) ? 'multipart/form-data' : 'application/json' => [
                        'schema' => $entry['request'],
                    ],
                ],
            ];
        }

        return $operation;
    }

    /**
     * Path parameters come from the URI and are therefore always complete;
     * query parameters are stated, because nothing in a route declares them.
     *
     * @param Route $route The route being described.
     * @param array<string, mixed> $entry The catalogue entry for it.
     *
     * @return array<int, array<string, mixed>> The operation's parameters.
     */
    private function parameters(Route $route, array $entry): array
    {
        $parameters = [];

        foreach ($route->parameterNames() as $parameter) {
            $parameters[] = [
                'name' => $parameter,
                'in' => 'path',
                'required' => true,
                'schema' => ['type' => 'string', 'format' => 'uuid'],
            ];
        }

        foreach (($entry['query'] ?? []) as $query) {
            $parameters[] = is_string($query)
                ? ['$ref' => '#/components/parameters/' . $query]
                : $query;
        }

        return $parameters;
    }

    /**
     * @param array<string, mixed> $entry The catalogue entry for the operation.
     *
     * @return array<int|string, array<string, mixed>> The operation's responses, success and failure alike. The status keys are numeric strings, which PHP stores as integers.
     */
    private function responses(array $entry): array
    {
        $status = (string) ($entry['status'] ?? 200);
        $schema = $entry['response'] ?? null;

        $success = $status === '204'
            ? ['description' => 'No content.']
            : [
                'description' => $entry['returns'] ?? 'Success.',
                'content' => ['application/json' => ['schema' => $schema ?? ['type' => 'object']]],
            ];

        if (($entry['binary'] ?? false) === true) {
            $success = [
                'description' => $entry['returns'] ?? 'The file.',
                'content' => ['application/octet-stream' => [
                    'schema' => ['type' => 'string', 'format' => 'binary'],
                ]],
            ];
        }

        $responses = [$status => $success];

        if (($entry['public'] ?? false) !== true) {
            $responses['401'] = ['$ref' => '#/components/responses/Unauthorized'];
            $responses['403'] = ['$ref' => '#/components/responses/Forbidden'];
        }

        $responses['404'] = ['$ref' => '#/components/responses/NotFound'];
        $responses['429'] = ['$ref' => '#/components/responses/TooManyRequests'];

        if (isset($entry['request']) || ($entry['validates'] ?? false)) {
            $responses['422'] = ['$ref' => '#/components/responses/ValidationFailed'];
        }

        return $responses;
    }

    /**
     * @return array<string, array<string, mixed>> Query parameters shared by many listings.
     */
    private function sharedParameters(): array
    {
        return [
            'PerPage' => [
                'name' => 'per_page',
                'in' => 'query',
                'required' => false,
                'description' => 'Rows per page, 1 to 100. Values outside that are clamped, not rejected, and meta.per_page reports what was used.',
                'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 15],
            ],
            'Page' => [
                'name' => 'page',
                'in' => 'query',
                'required' => false,
                'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1],
            ],
        ];
    }

    /**
     * @return array<string, array<string, mixed>> The error responses every operation can answer with.
     */
    private function sharedResponses(): array
    {
        $error = fn (string $description): array => [
            'description' => $description,
            'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/Error']]],
        ];

        return [
            'Unauthorized' => $error('No token, or an expired one.'),
            'Forbidden' => $error('The token is valid; its user may not do this.'),
            'NotFound' => $error("Not there, or not this user's to see. An id belonging to another workspace is reported as missing rather than as forbidden."),
            'TooManyRequests' => $error('Rate limited.'),
            'ValidationFailed' => [
                'description' => 'Validation failed.',
                'content' => ['application/json' => ['schema' => ['$ref' => '#/components/schemas/ValidationError']]],
            ],
        ];
    }

    /**
     * A reference to one of the component schemas.
     *
     * @param string $name The schema's name.
     *
     * @return array<string, string> The reference, as OpenAPI spells one.
     */
    private function ref(string $name): array
    {
        return ['$ref' => '#/components/schemas/' . $name];
    }

    /**
     * One resource, in the `data` envelope every response carries.
     *
     * @param string $name The schema's name.
     *
     * @return array<string, mixed> The wrapped shape.
     */
    private function one(string $name): array
    {
        return ['type' => 'object', 'properties' => ['data' => $this->ref($name)], 'required' => ['data']];
    }

    /**
     * Many of a resource, unpaginated — the listings small enough to answer whole.
     *
     * @param string $name The schema's name.
     *
     * @return array<string, mixed> The wrapped shape.
     */
    private function many(string $name): array
    {
        return [
            'type' => 'object',
            'properties' => ['data' => ['type' => 'array', 'items' => $this->ref($name)]],
            'required' => ['data'],
        ];
    }

    /**
     * A page of a resource, with the paginator's own links and meta.
     *
     * @param string $name The schema's name.
     * @param array<string, mixed> $extraMeta Any meta this listing adds of its own.
     *
     * @return array<string, mixed> The wrapped shape.
     */
    private function page(string $name, array $extraMeta = []): array
    {
        $meta = ['allOf' => [$this->ref('PaginationMeta')]];

        if ($extraMeta !== []) {
            $meta['allOf'][] = ['type' => 'object', 'properties' => $extraMeta];
        }

        return [
            'type' => 'object',
            'properties' => [
                'data' => ['type' => 'array', 'items' => $this->ref($name)],
                'links' => $this->ref('PaginationLinks'),
                'meta' => $meta,
            ],
            'required' => ['data', 'meta'],
        ];
    }

    /**
     * @param array<string, mixed> $properties The object's properties.
     * @param array<int, string> $required Which of them must be present.
     *
     * @return array<string, mixed> An object schema.
     */
    private function object(array $properties, array $required = []): array
    {
        $schema = ['type' => 'object', 'properties' => $properties];

        if ($required !== []) {
            $schema['required'] = $required;
        }

        return $schema;
    }

    /**
     * The shapes the API answers with.
     *
     * Stated rather than reflected off the resource classes: a resource's
     * `toArray()` decides what to include per request — `whenLoaded`,
     * `whenCounted`, the text that travels with one attachment and not with a
     * listing — and reading the method would describe one call rather than the
     * contract.
     *
     * @return array<string, array<string, mixed>> Every component schema.
     */
    private function schemas(): array
    {
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $date = ['type' => 'string', 'format' => 'date-time'];
        $person = $this->object(['id' => $uuid, 'name' => ['type' => 'string']]);

        return [
            'Error' => $this->object([
                'message' => ['type' => 'string'],
            ], ['message']),

            'ValidationError' => $this->object([
                'message' => ['type' => 'string'],
                'errors' => [
                    'type' => 'object',
                    'additionalProperties' => ['type' => 'array', 'items' => ['type' => 'string']],
                ],
            ], ['message', 'errors']),

            'PaginationLinks' => $this->object([
                'first' => ['type' => ['string', 'null']],
                'last' => ['type' => ['string', 'null']],
                'prev' => ['type' => ['string', 'null']],
                'next' => ['type' => ['string', 'null']],
            ]),

            'PaginationMeta' => $this->object([
                'current_page' => ['type' => 'integer'],
                'from' => ['type' => ['integer', 'null']],
                'last_page' => ['type' => 'integer'],
                'per_page' => ['type' => 'integer'],
                'to' => ['type' => ['integer', 'null']],
                'total' => ['type' => 'integer'],
            ]),

            'User' => $this->object([
                'id' => $uuid,
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string', 'format' => 'email'],
                'created_at' => $date,
            ]),

            'Workspace' => $this->object([
                'id' => $uuid,
                'name' => ['type' => 'string'],
                'created_at' => $date,
                'users_count' => ['type' => 'integer', 'description' => 'Only on the listing, which counts them.'],
            ]),

            'WorkspaceMember' => $this->object([
                'id' => array_merge($uuid, ['description' => "The user's id, which is what the member routes address them by."]),
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string', 'format' => 'email'],
                'role' => ['type' => 'string', 'enum' => ['admin', 'user']],
                'joined_at' => $date,
            ]),

            'UsageMetric' => $this->object([
                'used' => ['type' => 'integer'],
                'limit' => [
                    'type' => ['integer', 'null'],
                    'description' => 'Null means no limit, which is not the same as a limit of zero.',
                ],
            ]),

            'WorkspaceUsage' => $this->object([
                'storage' => $this->ref('UsageMetric'),
                'users' => $this->ref('UsageMetric'),
                'documents' => $this->ref('UsageMetric'),
                'attachments' => $this->ref('UsageMetric'),
            ]),

            'DocumentType' => $this->object([
                'id' => $uuid,
                'key' => ['type' => 'string', 'description' => 'What the organization rules match on.'],
                'name' => ['type' => 'string'],
                'documents_count' => ['type' => 'integer'],
            ]),

            'Tag' => $this->object([
                'id' => $uuid,
                'name' => ['type' => 'string'],
                'documents_count' => ['type' => 'integer'],
                'last_used_at' => $date,
            ]),

            'Document' => $this->object([
                'id' => $uuid,
                'workspace_id' => $uuid,
                'title' => ['type' => 'string'],
                'document_date' => ['type' => ['string', 'null'], 'format' => 'date'],
                'metadata' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'null']]],
                'created_at' => $date,
                'updated_at' => $date,
                'deleted_at' => array_merge($date, ['description' => 'Only present on something in the trash.']),
                'document_type' => $this->ref('DocumentType'),
                'tags' => ['type' => 'array', 'items' => $this->ref('Tag')],
                'creator' => $person,
                'current_location' => [
                    'type' => ['object', 'null'],
                    'description' => 'Where the physical document is now, not where it has been.',
                    'properties' => ['node_id' => $uuid, 'path' => ['type' => 'string']],
                ],
            ]),

            'Attachment' => $this->object([
                'id' => $uuid,
                'document_id' => $uuid,
                'filename' => ['type' => 'string'],
                'mime_type' => ['type' => 'string'],
                'size' => ['type' => 'integer'],
                'checksum' => ['type' => ['string', 'null']],
                'is_previewable' => [
                    'type' => 'boolean',
                    'description' => 'Whether this application will serve the file inline. An SVG is image/* and is still served as an opaque download, so this cannot be worked out from the mime type.',
                ],
                'ocr_status' => ['type' => 'string'],
                'ocr_text' => [
                    'type' => ['string', 'null'],
                    'description' => 'Only on a single attachment, never on a listing.',
                ],
                'created_at' => $date,
                'deleted_at' => $date,
                'file_uploaded_at' => array_merge($date, [
                    'description' => 'When the file sitting here now arrived, which stops agreeing with created_at the first time something replaces it.',
                ]),
                'uploader' => $person,
                'duplicate_of' => [
                    'type' => ['object', 'null'],
                    'properties' => [
                        'attachment_id' => $uuid,
                        'document_id' => $uuid,
                        'filename' => ['type' => 'string'],
                    ],
                ],
                'versions' => ['type' => 'array', 'items' => $this->ref('AttachmentVersion')],
            ]),

            'AttachmentVersion' => $this->object([
                'id' => $uuid,
                'attachment_id' => $uuid,
                'filename' => ['type' => 'string'],
                'mime_type' => ['type' => 'string'],
                'size' => ['type' => 'integer'],
                'uploaded_at' => $date,
                'superseded_at' => array_merge($date, [
                    'description' => 'When this file stopped being the current one. The chain is not numbered; these two dates are what identify a version.',
                ]),
                'uploader' => $person,
            ]),

            'OrganizationScheme' => $this->object([
                'id' => $uuid,
                'workspace_id' => $uuid,
                'name' => ['type' => 'string'],
                'created_at' => $date,
                'levels' => ['type' => 'array', 'items' => $this->ref('OrganizationLevel')],
                'rules' => ['type' => 'array', 'items' => $this->ref('OrganizationRule')],
            ]),

            'OrganizationLevel' => $this->object([
                'id' => $uuid,
                'scheme_id' => $uuid,
                'name' => ['type' => 'string'],
                'key' => ['type' => 'string'],
                'position' => ['type' => 'integer'],
                'capacity' => ['type' => ['integer', 'null'], 'description' => 'Null means no ceiling.'],
                'has_printable_label' => ['type' => 'boolean'],
                'value_strategy' => [
                    'type' => 'string',
                    'enum' => ['sequential', 'manual'],
                    'description' => 'Whether a node here is allocated a value or has to be given one.',
                ],
                'is_leaf' => ['type' => 'boolean', 'description' => 'The bottom tier: where documents come to rest.'],
            ]),

            'OrganizationRule' => $this->object([
                'id' => $uuid,
                'scheme_id' => $uuid,
                'matcher_key' => ['type' => 'string'],
                'matcher_value' => ['type' => 'string'],
                'target_level_id' => $uuid,
                'target_level' => $this->ref('OrganizationLevel'),
                'preferred_value' => ['type' => ['string', 'null']],
            ]),

            'OrganizationNode' => $this->object([
                'id' => $uuid,
                'level_id' => $uuid,
                'parent_id' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                'value' => ['type' => 'string'],
                'path' => [
                    'type' => 'string',
                    'description' => "Assembled by walking the node's ancestors, so no column holds it and nothing can be sorted by it.",
                ],
                'level' => $this->ref('OrganizationLevel'),
                'children_count' => ['type' => 'integer'],
            ]),

            'OrganizationLabel' => $this->object([
                'node_id' => $uuid,
                'path' => ['type' => 'string'],
                'level' => ['type' => 'string'],
                'url' => [
                    'type' => 'string',
                    'description' => 'What the printed code points at. The URL rather than a rendered image, so a client can choose its own size, margins and error correction.',
                ],
            ]),

            'IntakeLabel' => $this->object([
                'id' => $uuid,
                'kind' => ['type' => 'string'],
                'field' => ['type' => 'string'],
                'label' => ['type' => 'string'],
                'status' => ['type' => 'string', 'enum' => ['pending', 'accepted', 'rejected']],
                'support' => ['type' => 'integer', 'description' => 'How many times the archive saw it before proposing it.'],
            ]),

            'Task' => $this->object([
                'id' => $uuid,
                'workspace_id' => $uuid,
                'type' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'triggered_by' => $person,
                'subject' => ['type' => ['string', 'null']],
                'progress' => [
                    'type' => ['object', 'null'],
                    'description' => 'Counted in attachments rather than in queued jobs, and only while a sweep is running.',
                    'properties' => ['processed' => ['type' => 'integer'], 'total' => ['type' => 'integer']],
                ],
                'error' => ['type' => ['string', 'null']],
                'result_available' => [
                    'type' => 'boolean',
                    'description' => 'Whether there is a file to download. Where it sits on disk is not reported.',
                ],
                'started_at' => $date,
                'finished_at' => $date,
                'created_at' => $date,
            ]),

            'Activity' => $this->object([
                'id' => ['type' => 'integer'],
                'log_name' => ['type' => ['string', 'null']],
                'event' => ['type' => ['string', 'null']],
                'label' => [
                    'type' => ['string', 'null'],
                    'description' => 'What the entry is about, recorded when it happened so it survives the thing it names being deleted.',
                ],
                'subject_type' => ['type' => ['string', 'null']],
                'subject_id' => ['type' => ['string', 'null']],
                'causer' => $person,
                'created_at' => $date,
            ]),

            'CaptureSession' => $this->object([
                'id' => $uuid,
                'document_id' => $uuid,
                'status' => ['type' => 'string'],
                'is_active' => ['type' => 'boolean'],
                'replaces_attachment_id' => [
                    'type' => ['string', 'null'],
                    'format' => 'uuid',
                    'description' => 'Set when the session is aimed at replacing one file rather than adding a page. Such a session takes one photo and ends.',
                ],
                'expires_at' => $date,
                'created_at' => $date,
                'pairing_url' => [
                    'type' => 'string',
                    'description' => 'The signed link the phone loads and uploads through. The interface renders it as a QR code; this is the URL that code carries.',
                ],
            ]),

            'BulkReviewResult' => $this->object(['answered' => ['type' => 'integer']]),

            'MetadataSuggestions' => [
                'type' => 'object',
                'description' => 'Suggested values keyed by kind. Nothing is applied until it is accepted by kind.',
                'additionalProperties' => true,
            ],
        ];
    }

    /**
     * What each operation is for, what it takes and what it gives back.
     *
     * Keyed by route name with the version prefix stripped, which is also the
     * operationId. A route absent from here still appears in the spec, with
     * generic shapes — the gap reads as a thin entry rather than as a missing
     * endpoint, and `--check` does not hide it.
     *
     * @return array<string, array<string, mixed>> The catalogue.
     */
    private function catalogue(): array
    {
        $uuid = ['type' => 'string', 'format' => 'uuid'];
        $listing = ['PerPage', 'Page'];

        $documentBody = $this->object([
            'document_type_id' => $uuid,
            'title' => ['type' => 'string', 'maxLength' => 255],
            'document_date' => ['type' => ['string', 'null'], 'format' => 'date'],
            'metadata' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'null']]],
            'tag_ids' => [
                'type' => 'array',
                'items' => $uuid,
                'description' => 'Tags from another workspace are dropped rather than applied: a tag is a label, not an instruction.',
            ],
        ], ['document_type_id', 'title']);

        $typeBody = $this->object([
            'name' => ['type' => 'string', 'maxLength' => 255],
            'key' => ['type' => 'string', 'maxLength' => 255],
        ], ['name', 'key']);

        $tagBody = $this->object(['name' => ['type' => 'string', 'maxLength' => 255]], ['name']);

        $levelBody = $this->object([
            'name' => ['type' => 'string'],
            'key' => ['type' => 'string'],
            'capacity' => ['type' => ['integer', 'null']],
            'has_printable_label' => ['type' => 'boolean'],
            'value_strategy' => ['type' => 'string', 'enum' => ['sequential', 'manual']],
        ], ['name', 'key', 'value_strategy']);

        $ruleBody = $this->object([
            'matcher_key' => ['type' => 'string'],
            'matcher_value' => ['type' => 'string'],
            'target_level_id' => $uuid,
            'preferred_value' => ['type' => 'string'],
        ], ['matcher_key', 'matcher_value', 'target_level_id']);

        $roleBody = $this->object(['role' => ['type' => 'string', 'enum' => ['admin', 'user']]], ['role']);

        return [
            'openapi' => [
                'tag' => 'Identity',
                'summary' => 'This document, pointed at this installation',
                'public' => true,
                'returns' => "The OpenAPI spec, with `servers` resolved to the installation's own origin rather than the `{origin}` variable the committed file carries.",
                'response' => ['type' => 'object', 'description' => 'An OpenAPI 3.1 document.'],
            ],

            'user.show' => [
                'tag' => 'Identity',
                'summary' => 'Who this token belongs to',
                'returns' => "The token's owner.",
                'response' => $this->one('User'),
            ],

            // ── Workspaces ────────────────────────────────────────────────
            'workspaces.index' => [
                'tag' => 'Workspaces',
                'summary' => 'List the workspaces this token reaches',
                'returns' => "The user's workspaces, or every workspace for a platform admin.",
                'response' => $this->many('Workspace'),
            ],
            'workspaces.store' => [
                'tag' => 'Workspaces',
                'summary' => 'Create a workspace',
                'status' => 201,
                'request' => $this->object(['name' => ['type' => 'string']], ['name']),
                'response' => $this->one('Workspace'),
            ],
            'workspaces.show' => [
                'tag' => 'Workspaces',
                'summary' => 'Read a workspace',
                'response' => $this->one('Workspace'),
            ],
            'workspaces.update' => [
                'tag' => 'Workspaces',
                'summary' => 'Rename a workspace',
                'request' => $this->object(['name' => ['type' => 'string']], ['name']),
                'response' => $this->one('Workspace'),
            ],
            'workspaces.destroy' => [
                'tag' => 'Workspaces',
                'summary' => 'Delete a workspace and everything filed in it',
                'status' => 204,
                'validates' => true,
            ],
            'workspaces.usage' => [
                'tag' => 'Workspaces',
                'summary' => 'What the workspace uses, against what it is allowed',
                'response' => $this->one('WorkspaceUsage'),
            ],
            'workspaces.limits.update' => [
                'tag' => 'Workspaces',
                'summary' => "Set the workspace's ceilings",
                'request' => $this->object([
                    'storage_bytes' => ['type' => ['integer', 'null']],
                    'users' => ['type' => ['integer', 'null']],
                    'documents' => ['type' => ['integer', 'null']],
                    'attachments' => ['type' => ['integer', 'null']],
                ]),
                'returns' => 'The usage against the new limits.',
                'response' => $this->one('WorkspaceUsage'),
            ],
            'workspaces.users.index' => [
                'tag' => 'Workspaces',
                'summary' => 'List the members',
                'response' => $this->many('WorkspaceMember'),
            ],
            'workspaces.users.store' => [
                'tag' => 'Workspaces',
                'summary' => 'Add somebody, inviting them by email if they have no account',
                'status' => 201,
                'request' => $this->object([
                    'email' => ['type' => 'string', 'format' => 'email'],
                    'name' => ['type' => 'string'],
                    'role' => ['type' => 'string', 'enum' => ['admin', 'user']],
                ], ['email', 'role']),
                'response' => $this->one('WorkspaceMember'),
            ],
            'workspaces.users.update' => [
                'tag' => 'Workspaces',
                'summary' => "Change a member's role",
                'request' => $roleBody,
                'response' => $this->one('WorkspaceMember'),
            ],
            'workspaces.users.destroy' => [
                'tag' => 'Workspaces',
                'summary' => 'Remove a member',
                'status' => 204,
                'validates' => true,
            ],
            'workspaces.intake-labels.index' => [
                'tag' => 'Intake',
                'summary' => 'The phrases this archive taught itself',
                'query' => [['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['pending', 'accepted', 'rejected']]]],
                'response' => $this->many('IntakeLabel'),
            ],
            'workspaces.intake-labels.update' => [
                'tag' => 'Intake',
                'summary' => 'Accept, reject or retire a label',
                'request' => $this->object(['status' => ['type' => 'string', 'enum' => ['pending', 'accepted', 'rejected']]], ['status']),
                'response' => $this->one('IntakeLabel'),
            ],

            // ── Documents ─────────────────────────────────────────────────
            'documents.index' => [
                'tag' => 'Documents',
                'summary' => 'List and search documents',
                'query' => array_merge($listing, [
                    ['name' => 'q', 'in' => 'query', 'required' => false, 'description' => 'Free text over titles and the text extracted from scans.', 'schema' => ['type' => 'string', 'maxLength' => 255]],
                    ['name' => 'mode', 'in' => 'query', 'required' => false, 'description' => 'How q is matched. Defaults to all.', 'schema' => ['type' => 'string', 'enum' => ['all', 'any', 'phrase', 'title']]],
                    ['name' => 'document_type_id', 'in' => 'query', 'required' => false, 'schema' => $uuid],
                    ['name' => 'tag_ids[]', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'array', 'items' => $uuid]],
                    ['name' => 'node_id', 'in' => 'query', 'required' => false, 'description' => 'Where the document is now, not where it has been.', 'schema' => $uuid],
                    ['name' => 'from', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date']],
                    ['name' => 'to', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date']],
                    ['name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['title', 'document_date', 'type', 'created_at']]],
                    ['name' => 'direction', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc']]],
                ]),
                'validates' => true,
                'response' => $this->page('Document'),
            ],
            'documents.store' => [
                'tag' => 'Documents',
                'summary' => 'Register a document',
                'status' => 201,
                'request' => $documentBody,
                'response' => $this->one('Document'),
            ],
            'documents.show' => [
                'tag' => 'Documents',
                'summary' => 'Read a document',
                'response' => $this->one('Document'),
            ],
            'documents.update' => [
                'tag' => 'Documents',
                'summary' => 'Update a document',
                'request' => $documentBody,
                'response' => $this->one('Document'),
            ],
            'documents.destroy' => [
                'tag' => 'Documents',
                'summary' => 'Move a document to the trash',
                'status' => 204,
            ],
            'documents.move' => [
                'tag' => 'Documents',
                'summary' => 'File a document at a node, or let the scheme decide',
                'request' => $this->object([
                    'node_id' => array_merge($uuid, ['description' => 'The node to file it at. Mutually exclusive with scheme_id.']),
                    'scheme_id' => array_merge($uuid, ['description' => "Let this scheme's rules decide. Mutually exclusive with node_id."]),
                    'criteria' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => "Extra matcher criteria, merged with the document's type."],
                ]),
                'returns' => 'The document, with its new location.',
                'response' => $this->one('Document'),
            ],

            // ── Vocabulary ────────────────────────────────────────────────
            'document-types.index' => [
                'tag' => 'Vocabulary',
                'summary' => 'List the document types',
                'returns' => 'Every type, unpaginated.',
                'response' => $this->many('DocumentType'),
            ],
            'document-types.store' => ['tag' => 'Vocabulary', 'summary' => 'Create a document type', 'status' => 201, 'request' => $typeBody, 'response' => $this->one('DocumentType')],
            'document-types.update' => ['tag' => 'Vocabulary', 'summary' => 'Update a document type', 'request' => $typeBody, 'response' => $this->one('DocumentType')],
            'document-types.destroy' => [
                'tag' => 'Vocabulary',
                'summary' => 'Delete a document type',
                'status' => 204,
                'validates' => true,
            ],
            'tags.index' => ['tag' => 'Vocabulary', 'summary' => 'List the tags', 'returns' => 'Every tag, unpaginated.', 'response' => $this->many('Tag')],
            'tags.store' => ['tag' => 'Vocabulary', 'summary' => 'Create a tag', 'status' => 201, 'request' => $tagBody, 'response' => $this->one('Tag')],
            'tags.update' => ['tag' => 'Vocabulary', 'summary' => 'Rename a tag', 'request' => $tagBody, 'response' => $this->one('Tag')],
            'tags.destroy' => [
                'tag' => 'Vocabulary',
                'summary' => 'Delete a tag, detaching it from its documents',
                'status' => 204,
            ],

            // ── Attachments ───────────────────────────────────────────────
            'attachments.index' => ['tag' => 'Attachments', 'summary' => "List a document's attachments", 'response' => $this->many('Attachment')],
            'attachments.store' => [
                'tag' => 'Attachments',
                'summary' => 'Upload files onto a document',
                'status' => 201,
                'multipart' => true,
                'request' => $this->object([
                    'files' => [
                        'type' => 'array',
                        'items' => ['type' => 'string', 'format' => 'binary'],
                        'description' => 'Always a list, even for one file. A batch that fails validation or would cross a workspace limit fails whole.',
                    ],
                ], ['files']),
                'response' => $this->many('Attachment'),
            ],
            'attachments.show' => [
                'tag' => 'Attachments',
                'summary' => "Read an attachment's details and its reading",
                'returns' => 'The metadata, not the bytes. The file is at /file.',
                'response' => $this->one('Attachment'),
            ],
            'attachments.file' => ['tag' => 'Attachments', 'summary' => "Download an attachment's file", 'binary' => true],
            'attachments.preview' => [
                'tag' => 'Attachments',
                'summary' => 'Stream an attachment inline',
                'returns' => 'The file, inline where its type is safe to render and as an opaque download where it is not.',
                'binary' => true,
            ],
            'attachments.file.replace' => [
                'tag' => 'Attachments',
                'summary' => "Replace an attachment's file, keeping the old one",
                'multipart' => true,
                'request' => $this->object(['file' => ['type' => 'string', 'format' => 'binary']], ['file']),
                'returns' => 'The attachment, with the file it displaced now in its history.',
                'response' => $this->one('Attachment'),
            ],
            'attachments.destroy' => ['tag' => 'Attachments', 'summary' => 'Move an attachment to the trash', 'status' => 204],
            'attachments.duplicate.dismiss' => ['tag' => 'Attachments', 'summary' => 'Dismiss the duplicate warning', 'response' => $this->one('Attachment')],
            'attachments.extraction.store' => [
                'tag' => 'Attachments',
                'summary' => 'Read the file again',
                'validates' => true,
                'response' => $this->one('Attachment'),
            ],
            'attachments.reading.confirm' => ['tag' => 'Attachments', 'summary' => 'Keep what OCR made of it', 'response' => $this->one('Attachment')],
            'attachments.reading.reject' => [
                'tag' => 'Attachments',
                'summary' => 'Throw away what OCR made of it',
                'returns' => 'The attachment. The text is discarded rather than flagged, because it feeds the search index and the duplicate fingerprint.',
                'response' => $this->one('Attachment'),
            ],
            'attachments.versions.index' => [
                'tag' => 'Attachments',
                'summary' => 'The files an attachment used to hold',
                'response' => $this->many('AttachmentVersion'),
            ],
            'attachment-versions.file' => ['tag' => 'Attachments', 'summary' => 'Download a superseded file', 'binary' => true],
            'attachment-versions.restore' => [
                'tag' => 'Attachments',
                'summary' => 'Make a superseded file current again',
                'returns' => 'The attachment, now holding the restored file, with the one it displaced behind it.',
                'response' => $this->one('Attachment'),
            ],

            // ── Trash ─────────────────────────────────────────────────────
            'trash.documents.index' => [
                'tag' => 'Trash',
                'summary' => 'Documents in the trash',
                'query' => $listing,
                'response' => $this->page('Document', ['retention_days' => ['type' => 'integer']]),
            ],
            'trash.attachments.index' => [
                'tag' => 'Trash',
                'summary' => 'Attachments in the trash',
                'returns' => 'Includes the ones that went down with their document.',
                'query' => $listing,
                'response' => $this->page('Attachment', ['retention_days' => ['type' => 'integer']]),
            ],
            'trash.documents.restore' => ['tag' => 'Trash', 'summary' => 'Take a document back out', 'response' => $this->one('Document')],
            'trash.documents.purge' => ['tag' => 'Trash', 'summary' => 'Destroy a document for good', 'status' => 204],
            'trash.attachments.restore' => ['tag' => 'Trash', 'summary' => 'Take an attachment back out', 'response' => $this->one('Attachment')],
            'trash.attachments.purge' => ['tag' => 'Trash', 'summary' => 'Destroy an attachment for good', 'status' => 204],
            'trash.empty' => ['tag' => 'Trash', 'summary' => 'Empty the trash', 'status' => 204],

            // ── Organization ──────────────────────────────────────────────
            'organization.schemes.index' => ['tag' => 'Organization', 'summary' => 'List the schemes', 'response' => $this->many('OrganizationScheme')],
            'organization.schemes.store' => [
                'tag' => 'Organization',
                'summary' => 'Create a scheme with the levels it is made of',
                'status' => 201,
                'request' => $this->object([
                    'name' => ['type' => 'string'],
                    'levels' => ['type' => 'array', 'items' => $levelBody, 'description' => 'In order, top tier first.'],
                ], ['name', 'levels']),
                'response' => $this->one('OrganizationScheme'),
            ],
            'organization.schemes.show' => [
                'tag' => 'Organization',
                'summary' => 'Read a scheme, its levels and its rules',
                'returns' => 'What a client needs before it can file automatically.',
                'response' => $this->one('OrganizationScheme'),
            ],
            'organization.schemes.update' => [
                'tag' => 'Organization',
                'summary' => 'Rename a scheme',
                'request' => $this->object(['name' => ['type' => 'string']], ['name']),
                'response' => $this->one('OrganizationScheme'),
            ],
            'organization.schemes.levels.store' => ['tag' => 'Organization', 'summary' => 'Add a level to the bottom of a scheme', 'status' => 201, 'request' => $levelBody, 'response' => $this->one('OrganizationLevel')],
            'organization.schemes.levels.update' => [
                'tag' => 'Organization',
                'summary' => "Change whether a level's nodes carry printable labels",
                'request' => $this->object(['has_printable_label' => ['type' => 'boolean']], ['has_printable_label']),
                'response' => $this->one('OrganizationLevel'),
            ],
            'organization.schemes.levels.destroy' => ['tag' => 'Organization', 'summary' => 'Remove a level', 'status' => 204, 'validates' => true],
            'organization.schemes.nodes.index' => [
                'tag' => 'Organization',
                'summary' => 'Browse a tier of the archive',
                'query' => array_merge($listing, [[
                    'name' => 'parent_id',
                    'in' => 'query',
                    'required' => false,
                    'description' => "The node whose children are wanted. Without it, the root tier's nodes come back.",
                    'schema' => $uuid,
                ]]),
                'response' => $this->page('OrganizationNode'),
            ],
            'organization.schemes.nodes.store' => [
                'tag' => 'Organization',
                'summary' => 'Create a node',
                'status' => 201,
                'request' => $this->object([
                    'level_id' => $uuid,
                    'parent_id' => ['type' => ['string', 'null'], 'format' => 'uuid'],
                    'value' => ['type' => ['string', 'null'], 'description' => 'Required where the level allocates by hand; allocated for you where it does not.'],
                ], ['level_id']),
                'response' => $this->one('OrganizationNode'),
            ],
            'organization.schemes.nodes.destroy' => ['tag' => 'Organization', 'summary' => 'Delete a node', 'status' => 204, 'validates' => true],
            'organization.nodes.documents' => [
                'tag' => 'Organization',
                'summary' => 'What is filed at a node now',
                'query' => $listing,
                'response' => $this->page('Document'),
            ],
            'organization.nodes.migrate' => [
                'tag' => 'Organization',
                'summary' => 'Move everything at a node to another one',
                'status' => 202,
                'returns' => 'Queued. Read the task to follow it.',
                'request' => $this->object(['target_node_id' => $uuid], ['target_node_id']),
            ],
            'organization.schemes.rules.store' => ['tag' => 'Organization', 'summary' => 'Add a matching rule', 'status' => 201, 'request' => $ruleBody, 'response' => $this->one('OrganizationRule')],
            'organization.schemes.rules.update' => ['tag' => 'Organization', 'summary' => 'Change a matching rule', 'request' => $ruleBody, 'response' => $this->one('OrganizationRule')],
            'organization.schemes.rules.destroy' => ['tag' => 'Organization', 'summary' => 'Delete a matching rule', 'status' => 204],
            'organization.schemes.labels' => [
                'tag' => 'Organization',
                'summary' => 'The nodes that carry a printable label',
                'query' => [['name' => 'level_id', 'in' => 'query', 'required' => false, 'schema' => $uuid]],
                'response' => $this->many('OrganizationLabel'),
            ],

            // ── Intake and capture ────────────────────────────────────────
            'documents.review.index' => [
                'tag' => 'Intake',
                'summary' => 'The documents waiting on an answer',
                'query' => array_merge($listing, [[
                    'name' => 'filter',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'string', 'enum' => ['all', 'suggestions', 'readings', 'duplicates'], 'default' => 'all'],
                ]]),
                'response' => $this->page('Document', [
                    'filter' => ['type' => 'string'],
                    'counts' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer'], 'description' => 'How many documents each filter holds.'],
                ]),
            ],
            'documents.review.bulk' => [
                'tag' => 'Intake',
                'summary' => 'Answer for many documents at once',
                'request' => $this->object([
                    'action' => ['type' => 'string', 'enum' => ['accept_suggestions', 'dismiss_readings', 'dismiss_duplicates']],
                    'filter' => [
                        'type' => 'string',
                        'enum' => ['all', 'suggestions', 'readings', 'duplicates'],
                        'description' => 'The queue the answer was made against, so answering everything means everything you were looking at rather than everything that qualifies when the request lands.',
                    ],
                    'documents' => ['type' => 'array', 'items' => $uuid, 'description' => 'Omit to answer for the whole filtered queue.'],
                ], ['action']),
                'returns' => 'How many documents were answered.',
                'response' => $this->one('BulkReviewResult'),
            ],
            'documents.suggestions.index' => [
                'tag' => 'Intake',
                'summary' => 'What the archive would suggest for a document',
                'returns' => 'Applies nothing.',
                'response' => $this->one('MetadataSuggestions'),
            ],
            'documents.suggestions.accept' => [
                'tag' => 'Intake',
                'summary' => 'Accept some of the suggested metadata',
                'request' => $this->object([
                    'kinds' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 64]],
                ], ['kinds']),
                'response' => $this->one('Document'),
            ],
            'capture-sessions.store' => [
                'tag' => 'Intake',
                'summary' => 'Pair a phone with a document',
                'status' => 201,
                'request' => $this->object([
                    'attachment' => array_merge($uuid, [
                        'description' => 'Aims the session at replacing this file rather than adding a page. Such a session takes one photo and ends.',
                    ]),
                ]),
                'response' => $this->one('CaptureSession'),
            ],
            'capture-sessions.show' => [
                'tag' => 'Intake',
                'summary' => 'Has the phone finished?',
                'response' => $this->one('CaptureSession'),
            ],
            'capture-sessions.cancel' => [
                'tag' => 'Intake',
                'summary' => 'End a pairing session early',
                'response' => $this->one('CaptureSession'),
            ],

            // ── Tasks and activity ────────────────────────────────────────
            'workspaces.tasks.index' => [
                'tag' => 'Tasks',
                'summary' => 'Background work, most recent first',
                'query' => array_merge($listing, [
                    ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'type', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                ]),
                'response' => $this->page('Task'),
            ],
            'workspaces.tasks.store' => [
                'tag' => 'Tasks',
                'summary' => 'Start a document export',
                'status' => 202,
                'returns' => 'Queued. Read the task until it finishes.',
                'validates' => true,
                'response' => $this->one('Task'),
            ],
            'workspaces.tasks.reextract' => [
                'tag' => 'Tasks',
                'summary' => 'Read every attachment in the workspace again',
                'status' => 202,
                'returns' => 'Queued. One task stands for the whole sweep.',
                'validates' => true,
                'response' => $this->one('Task'),
            ],
            'workspaces.tasks.show' => ['tag' => 'Tasks', 'summary' => 'Poll one task', 'response' => $this->one('Task')],
            'workspaces.tasks.retry' => ['tag' => 'Tasks', 'summary' => 'Run a failed task again', 'validates' => true, 'response' => $this->one('Task')],
            'workspaces.tasks.download' => [
                'tag' => 'Tasks',
                'summary' => 'Fetch a finished export',
                'returns' => 'The export. Every reason there might not be one — wrong kind of task, still running, failed, pruned — answers 404.',
                'binary' => true,
            ],
            'workspaces.activity.index' => [
                'tag' => 'Tasks',
                'summary' => "The workspace's audit trail",
                'query' => array_merge($listing, [
                    ['name' => 'event', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'log_name', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                ]),
                'response' => $this->page('Activity'),
            ],
        ];
    }
}
