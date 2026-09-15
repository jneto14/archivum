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
            ['name' => 'Meta', 'description' => 'The API describing itself.'],
            ['name' => 'Identity', 'description' => 'The account a token belongs to.'],
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
     * Turn every versioned route into an operation, in the order the
     * catalogue names them.
     *
     * Order matters and is not alphabetical. A client that imports this file
     * lists the operations in document order, so a tag whose entries arrive
     * sorted by URL reads as a jumble — read, update, move, file, list —
     * where what anybody wants is the order they would be used in: list, then
     * one, then create, then change it. The catalogue is written in that
     * order and this preserves it.
     *
     * A route the catalogue does not mention is still emitted, after the rest.
     * A gap should look like a gap, not like a missing endpoint.
     *
     * @return array<string, array<string, mixed>> Paths, each holding its methods.
     */
    private function paths(): array
    {
        $catalogue = $this->catalogue();
        $routes = [];

        foreach (Router::getRoutes()->getRoutes() as $route) {
            $name = (string) $route->getName();

            if (str_starts_with($name, self::ROUTE_PREFIX)) {
                $routes[Str::after($name, self::ROUTE_PREFIX)] = $route;
            }
        }

        // The catalogue leads: walk it in order and take the route each entry
        // names, then let anything it does not name follow at the end.
        $ordered = [];

        foreach (array_keys($catalogue) as $key) {
            if (isset($routes[$key])) {
                $ordered[$key] = $routes[$key];
            }
        }

        foreach ($routes as $key => $route) {
            $ordered[$key] ??= $route;
        }

        $paths = [];

        foreach ($ordered as $key => $route) {
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
                // So an imported collection shows the shape of the thing that
                // goes here instead of an empty box. Substitute a real id.
                'example' => '01998fa8-0000-7000-8000-000000000000',
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
                'email_verified_at' => array_merge($date, [
                    'description' => 'Cleared when the email changes, so a client that has just changed it can see the new address needs confirming.',
                ]),
                'timezone' => [
                    'type' => ['string', 'null'],
                    'description' => "The user's own setting, and what the interface renders their dates in. Timestamps in this API are ISO 8601 in UTC regardless.",
                ],
                'locale' => [
                    'type' => ['string', 'null'],
                    'description' => 'Which language the application answers this user in, validation messages included.',
                ],
                'is_platform_admin' => ['type' => 'boolean'],
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
     * What each operation is called, what it is for, what it takes and what it
     * gives back.
     *
     * Keyed by route name with the version prefix stripped, which is also the
     * operationId. **The order here is the order the spec comes out in**, and
     * therefore the order a client lists these in, so it is written the way
     * somebody would work through them rather than the way the URLs sort.
     *
     * `summary` is a name, not a sentence: a few words, verb first, because
     * that is all a sidebar shows before it truncates. Anything worth saying
     * beyond that goes in `description`, which is read deliberately.
     *
     * A route absent from here still appears in the spec, with generic shapes
     * and at the end — the gap reads as a thin entry rather than as a missing
     * endpoint, and a test fails on exactly that.
     *
     * @return array<string, array<string, mixed>> The catalogue, in order.
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

        return [
            // ── Meta ──────────────────────────────────────────────────────
            'openapi' => [
                'tag' => 'Meta',
                'summary' => 'Get the API spec',
                'public' => true,
                'description' => "This document, with `servers` resolved to the installation's own origin rather than the `{origin}` variable the committed file carries. The one endpoint that needs no token: it describes how to authenticate, so requiring authentication to read it would be a bootstrapping problem.",
                'returns' => 'An OpenAPI 3.1 document.',
                'response' => ['type' => 'object'],
            ],

            // ── Identity ──────────────────────────────────────────────────
            'user.show' => [
                'tag' => 'Identity',
                'summary' => 'Get my profile',
                'description' => 'Who this token belongs to. A token reaches its own account and nobody else\'s, which is why no user id appears in this route.',
                'response' => $this->one('User'),
            ],
            'user.update' => [
                'tag' => 'Identity',
                'summary' => 'Update my profile',
                'description' => 'Name, email, timezone and language. Changing the email clears its verification, as it does in the browser. Deleting the account and changing the password are not here: both turn on proving the current password, which a token holder may well not have.',
                'request' => $this->object([
                    'name' => ['type' => 'string', 'maxLength' => 255],
                    'email' => ['type' => 'string', 'format' => 'email', 'maxLength' => 255],
                    'timezone' => ['type' => ['string', 'null'], 'description' => 'Any IANA identifier.'],
                    'locale' => ['type' => ['string', 'null']],
                ], ['name', 'email']),
                'response' => $this->one('User'),
            ],

            // ── Workspaces ────────────────────────────────────────────────
            'workspaces.index' => [
                'tag' => 'Workspaces',
                'summary' => 'List workspaces',
                'description' => 'Start here: every other route names a workspace in its path and a token carries no default one. A platform admin gets all of them.',
                'response' => $this->many('Workspace'),
            ],
            'workspaces.show' => [
                'tag' => 'Workspaces',
                'summary' => 'Get a workspace',
                'response' => $this->one('Workspace'),
            ],
            'workspaces.store' => [
                'tag' => 'Workspaces',
                'summary' => 'Create a workspace',
                'status' => 201,
                'request' => $this->object(['name' => ['type' => 'string']], ['name']),
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
                'summary' => 'Delete a workspace',
                'description' => 'And everything filed in it. A workspace does not soft-delete, so this unlinks every file it holds, including whatever was in its own trash.',
                'status' => 204,
                'validates' => true,
            ],
            'workspaces.usage' => [
                'tag' => 'Workspaces',
                'summary' => 'Get usage and limits',
                'description' => 'A missing limit is reported as null, which is not the same as a limit of zero.',
                'response' => $this->one('WorkspaceUsage'),
            ],
            'workspaces.limits.update' => [
                'tag' => 'Workspaces',
                'summary' => 'Set limits',
                'description' => 'Platform admins. Answers with the usage against the new ceilings, so a client does not have to follow this with another call.',
                'request' => $this->object([
                    'storage_bytes' => ['type' => ['integer', 'null']],
                    'users' => ['type' => ['integer', 'null']],
                    'documents' => ['type' => ['integer', 'null']],
                    'attachments' => ['type' => ['integer', 'null']],
                ]),
                'response' => $this->one('WorkspaceUsage'),
            ],
            'workspaces.users.index' => [
                'tag' => 'Workspaces',
                'summary' => 'List members',
                'response' => $this->many('WorkspaceMember'),
            ],
            'workspaces.users.store' => [
                'tag' => 'Workspaces',
                'summary' => 'Add a member',
                'description' => 'Invites them by email if they have no account yet, through the same invitation the interface sends.',
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
                'description' => 'The last admin cannot be demoted.',
                'request' => $this->object(['role' => ['type' => 'string', 'enum' => ['admin', 'user']]], ['role']),
                'response' => $this->one('WorkspaceMember'),
            ],
            'workspaces.users.destroy' => [
                'tag' => 'Workspaces',
                'summary' => 'Remove a member',
                'description' => 'The last admin cannot be removed.',
                'status' => 204,
                'validates' => true,
            ],

            // ── Documents ─────────────────────────────────────────────────
            'documents.index' => [
                'tag' => 'Documents',
                'summary' => 'List and search documents',
                'description' => 'This is also the search endpoint: `q` and `mode` are filters on it rather than a separate surface. Workspace scoping is enforced here and is never client-controlled.',
                'query' => array_merge($listing, [
                    ['name' => 'q', 'in' => 'query', 'required' => false, 'description' => 'Free text over titles and the text extracted from scans.', 'schema' => ['type' => 'string', 'maxLength' => 255]],
                    ['name' => 'mode', 'in' => 'query', 'required' => false, 'description' => 'How q is matched.', 'schema' => ['type' => 'string', 'enum' => ['all', 'any', 'phrase', 'title'], 'default' => 'all']],
                    ['name' => 'document_type_id', 'in' => 'query', 'required' => false, 'schema' => $uuid],
                    ['name' => 'tag_ids[]', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'array', 'items' => $uuid]],
                    ['name' => 'node_id', 'in' => 'query', 'required' => false, 'description' => 'Where the document is now, not where it has been.', 'schema' => $uuid],
                    ['name' => 'from', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date']],
                    ['name' => 'to', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'format' => 'date']],
                    ['name' => 'sort', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['title', 'document_date', 'type', 'created_at'], 'default' => 'created_at']],
                    ['name' => 'direction', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc']],
                ]),
                'validates' => true,
                'response' => $this->page('Document'),
            ],
            'documents.show' => [
                'tag' => 'Documents',
                'summary' => 'Get a document',
                'response' => $this->one('Document'),
            ],
            'documents.store' => [
                'tag' => 'Documents',
                'summary' => 'Register a document',
                'description' => 'A document type from another workspace answers 404 rather than filing the document under it.',
                'status' => 201,
                'request' => $documentBody,
                'response' => $this->one('Document'),
            ],
            'documents.update' => [
                'tag' => 'Documents',
                'summary' => 'Update a document',
                'response' => $this->one('Document'),
                'request' => $documentBody,
            ],
            'documents.destroy' => [
                'tag' => 'Documents',
                'summary' => 'Trash a document',
                'description' => 'Reversible. Destroying it for good is on the trash endpoints, under a narrower policy.',
                'status' => 204,
            ],
            'documents.move' => [
                'tag' => 'Documents',
                'summary' => 'File a document',
                'description' => "Either at a node you name, or wherever the scheme's rules say it belongs. The second form is the one worth having: a client filing a batch does not know the archive's shelves.",
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
                'summary' => 'List document types',
                'description' => 'Unpaginated: a workspace has a handful, and a client mapping its own vocabulary onto this one wants the whole set at once.',
                'response' => $this->many('DocumentType'),
            ],
            'document-types.store' => ['tag' => 'Vocabulary', 'summary' => 'Create a document type', 'status' => 201, 'request' => $typeBody, 'response' => $this->one('DocumentType')],
            'document-types.update' => ['tag' => 'Vocabulary', 'summary' => 'Update a document type', 'request' => $typeBody, 'response' => $this->one('DocumentType')],
            'document-types.destroy' => [
                'tag' => 'Vocabulary',
                'summary' => 'Delete a document type',
                'description' => 'Refused while documents are still filed under it.',
                'status' => 204,
                'validates' => true,
            ],
            'tags.index' => [
                'tag' => 'Vocabulary',
                'summary' => 'List tags',
                'description' => 'Unpaginated, and carrying how many documents wear each and when it was last applied.',
                'response' => $this->many('Tag'),
            ],
            'tags.store' => ['tag' => 'Vocabulary', 'summary' => 'Create a tag', 'status' => 201, 'request' => $tagBody, 'response' => $this->one('Tag')],
            'tags.update' => ['tag' => 'Vocabulary', 'summary' => 'Rename a tag', 'request' => $tagBody, 'response' => $this->one('Tag')],
            'tags.destroy' => [
                'tag' => 'Vocabulary',
                'summary' => 'Delete a tag',
                'description' => 'Unlike a document type, a tag in use is not protected: losing a label is not losing a filing.',
                'status' => 204,
            ],

            // ── Attachments ───────────────────────────────────────────────
            'attachments.index' => ['tag' => 'Attachments', 'summary' => 'List attachments', 'response' => $this->many('Attachment')],
            'attachments.store' => [
                'tag' => 'Attachments',
                'summary' => 'Upload attachments',
                'description' => 'Multipart, always a list even for one file. A batch that fails validation or would cross a workspace limit fails whole, so a document is never left half changed.',
                'status' => 201,
                'multipart' => true,
                'request' => $this->object([
                    'files' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'binary']],
                ], ['files']),
                'response' => $this->many('Attachment'),
            ],
            'attachments.show' => [
                'tag' => 'Attachments',
                'summary' => 'Get an attachment',
                'description' => 'The metadata and the extracted text, not the bytes — those are at /file. The text travels here and never with a listing, where fifty scans would turn a listing into a transfer.',
                'response' => $this->one('Attachment'),
            ],
            'attachments.file' => ['tag' => 'Attachments', 'summary' => 'Download an attachment', 'binary' => true],
            'attachments.preview' => [
                'tag' => 'Attachments',
                'summary' => 'Preview an attachment',
                'description' => 'Inline where the type is safe to render, and as an opaque download where it is not — an SVG is image/* and is still not rendered.',
                'binary' => true,
            ],
            'attachments.file.replace' => [
                'tag' => 'Attachments',
                'summary' => "Replace an attachment's file",
                'description' => 'The file it displaces goes into the history behind it rather than being lost.',
                'multipart' => true,
                'request' => $this->object(['file' => ['type' => 'string', 'format' => 'binary']], ['file']),
                'response' => $this->one('Attachment'),
            ],
            'attachments.destroy' => ['tag' => 'Attachments', 'summary' => 'Trash an attachment', 'status' => 204],
            'attachments.versions.index' => [
                'tag' => 'Attachments',
                'summary' => 'List previous files',
                'description' => 'What this attachment used to hold, newest replacement first. Not numbered: the chain is the set of files it is *not* currently holding.',
                'response' => $this->many('AttachmentVersion'),
            ],
            'attachment-versions.file' => ['tag' => 'Attachments', 'summary' => 'Download a previous file', 'binary' => true],
            'attachment-versions.restore' => [
                'tag' => 'Attachments',
                'summary' => 'Restore a previous file',
                'description' => 'A swap, not an upload: the restored file keeps the path it already has, and the one it displaces takes its place in the history.',
                'response' => $this->one('Attachment'),
            ],
            'attachments.extraction.store' => [
                'tag' => 'Attachments',
                'summary' => 'Re-read an attachment',
                'validates' => true,
                'response' => $this->one('Attachment'),
            ],
            'attachments.reading.confirm' => ['tag' => 'Attachments', 'summary' => 'Keep a reading', 'response' => $this->one('Attachment')],
            'attachments.reading.reject' => [
                'tag' => 'Attachments',
                'summary' => 'Discard a reading',
                'description' => 'The text is thrown away rather than flagged: it feeds the search index and the duplicate fingerprint, so a reading nobody believes has to stop being one.',
                'response' => $this->one('Attachment'),
            ],
            'attachments.duplicate.dismiss' => [
                'tag' => 'Attachments',
                'summary' => 'Dismiss a duplicate warning',
                'response' => $this->one('Attachment'),
            ],

            // ── Trash ─────────────────────────────────────────────────────
            'trash.documents.index' => [
                'tag' => 'Trash',
                'summary' => 'List trashed documents',
                'query' => $listing,
                'response' => $this->page('Document', ['retention_days' => ['type' => 'integer']]),
            ],
            'trash.documents.restore' => ['tag' => 'Trash', 'summary' => 'Restore a document', 'response' => $this->one('Document')],
            'trash.documents.purge' => [
                'tag' => 'Trash',
                'summary' => 'Destroy a document',
                'description' => 'For good, with its attachments and their files. Narrower than trashing it, because trashing is reversible and this is not.',
                'status' => 204,
            ],
            'trash.attachments.index' => [
                'tag' => 'Trash',
                'summary' => 'List trashed attachments',
                'description' => 'Includes the ones that went down with their document, which the interface hides because the document\'s own row stands for them.',
                'query' => $listing,
                'response' => $this->page('Attachment', ['retention_days' => ['type' => 'integer']]),
            ],
            'trash.attachments.restore' => ['tag' => 'Trash', 'summary' => 'Restore an attachment', 'response' => $this->one('Attachment')],
            'trash.attachments.purge' => ['tag' => 'Trash', 'summary' => 'Destroy an attachment', 'status' => 204],
            'trash.empty' => ['tag' => 'Trash', 'summary' => 'Empty the trash', 'status' => 204],

            // ── Organization ──────────────────────────────────────────────
            'organization.schemes.index' => ['tag' => 'Organization', 'summary' => 'List schemes', 'response' => $this->many('OrganizationScheme')],
            'organization.schemes.show' => [
                'tag' => 'Organization',
                'summary' => 'Get a scheme',
                'description' => 'With its levels in order and the rules that file into them — what a client needs before it can file automatically.',
                'response' => $this->one('OrganizationScheme'),
            ],
            'organization.schemes.store' => [
                'tag' => 'Organization',
                'summary' => 'Create a scheme',
                'description' => 'With the levels it is made of, in order: a scheme with no levels can hold nothing.',
                'status' => 201,
                'request' => $this->object([
                    'name' => ['type' => 'string'],
                    'levels' => ['type' => 'array', 'items' => $levelBody],
                ], ['name', 'levels']),
                'response' => $this->one('OrganizationScheme'),
            ],
            'organization.schemes.update' => [
                'tag' => 'Organization',
                'summary' => 'Rename a scheme',
                'request' => $this->object(['name' => ['type' => 'string']], ['name']),
                'response' => $this->one('OrganizationScheme'),
            ],
            'organization.schemes.levels.store' => ['tag' => 'Organization', 'summary' => 'Add a level', 'status' => 201, 'request' => $levelBody, 'response' => $this->one('OrganizationLevel')],
            'organization.schemes.levels.update' => [
                'tag' => 'Organization',
                'summary' => 'Update a level',
                'request' => $this->object(['has_printable_label' => ['type' => 'boolean']], ['has_printable_label']),
                'response' => $this->one('OrganizationLevel'),
            ],
            'organization.schemes.levels.destroy' => ['tag' => 'Organization', 'summary' => 'Remove a level', 'status' => 204, 'validates' => true],
            'organization.schemes.nodes.index' => [
                'tag' => 'Organization',
                'summary' => 'Browse nodes',
                'description' => "One tier at a time. Without `parent_id` the root tier comes back; with it, that node's children. A client opening one cover does not want every shelf in the building.",
                'query' => array_merge($listing, [[
                    'name' => 'parent_id',
                    'in' => 'query',
                    'required' => false,
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
                'summary' => 'List a node\'s documents',
                'description' => 'What is filed there now, not what has been.',
                'query' => $listing,
                'response' => $this->page('Document'),
            ],
            'organization.nodes.migrate' => [
                'tag' => 'Organization',
                'summary' => "Move a node's documents",
                'description' => 'Queued — a full cover is a lot of documents. Read the task to follow it.',
                'status' => 202,
                'request' => $this->object(['target_node_id' => $uuid], ['target_node_id']),
            ],
            'organization.schemes.rules.store' => ['tag' => 'Organization', 'summary' => 'Add a filing rule', 'status' => 201, 'request' => $ruleBody, 'response' => $this->one('OrganizationRule')],
            'organization.schemes.rules.update' => ['tag' => 'Organization', 'summary' => 'Update a filing rule', 'request' => $ruleBody, 'response' => $this->one('OrganizationRule')],
            'organization.schemes.rules.destroy' => ['tag' => 'Organization', 'summary' => 'Delete a filing rule', 'status' => 204],
            'organization.schemes.labels' => [
                'tag' => 'Organization',
                'summary' => 'List printable labels',
                'description' => 'The nodes that carry one, with the URL their code points at rather than a rendered image — a client making labels has its own idea of size, margins and error correction.',
                'query' => [['name' => 'level_id', 'in' => 'query', 'required' => false, 'schema' => $uuid]],
                'response' => $this->many('OrganizationLabel'),
            ],

            // ── Intake ────────────────────────────────────────────────────
            'documents.review.index' => [
                'tag' => 'Intake',
                'summary' => 'List the review queue',
                'description' => 'The documents waiting on an answer, with the counts for every filter alongside — they are what decides which queue is worth working, and a client has no tab strip to read them off.',
                'query' => array_merge($listing, [[
                    'name' => 'filter',
                    'in' => 'query',
                    'required' => false,
                    'schema' => ['type' => 'string', 'enum' => ['all', 'suggestions', 'readings', 'duplicates'], 'default' => 'all'],
                ]]),
                'response' => $this->page('Document', [
                    'filter' => ['type' => 'string'],
                    'counts' => ['type' => 'object', 'additionalProperties' => ['type' => 'integer']],
                ]),
            ],
            'documents.review.bulk' => [
                'tag' => 'Intake',
                'summary' => 'Answer the review queue',
                'description' => 'For many documents at once. The filter travels with the answer, so answering everything means everything in the queue you were looking at rather than everything that qualifies by the time the request lands.',
                'request' => $this->object([
                    'action' => ['type' => 'string', 'enum' => ['accept_suggestions', 'dismiss_readings', 'dismiss_duplicates']],
                    'filter' => ['type' => 'string', 'enum' => ['all', 'suggestions', 'readings', 'duplicates']],
                    'documents' => ['type' => 'array', 'items' => $uuid, 'description' => 'Omit to answer for the whole filtered queue.'],
                ], ['action']),
                'returns' => 'How many documents were answered.',
                'response' => $this->one('BulkReviewResult'),
            ],
            'documents.suggestions.index' => [
                'tag' => 'Intake',
                'summary' => 'Get metadata suggestions',
                'description' => 'What the archive would suggest for this document. Applies nothing.',
                'response' => $this->one('MetadataSuggestions'),
            ],
            'documents.suggestions.accept' => [
                'tag' => 'Intake',
                'summary' => 'Accept metadata suggestions',
                'description' => 'By kind rather than wholesale: taking the date and leaving the counterparty is the ordinary case.',
                'request' => $this->object([
                    'kinds' => ['type' => 'array', 'items' => ['type' => 'string', 'maxLength' => 64]],
                ], ['kinds']),
                'response' => $this->one('Document'),
            ],
            'workspaces.intake-labels.index' => [
                'tag' => 'Intake',
                'summary' => 'List learned labels',
                'description' => 'The phrases this archive taught itself. Every one of them, unlike the settings screen, which shows only the accepted.',
                'query' => [['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string', 'enum' => ['pending', 'accepted', 'rejected']]]],
                'response' => $this->many('IntakeLabel'),
            ],
            'workspaces.intake-labels.update' => [
                'tag' => 'Intake',
                'summary' => 'Answer a learned label',
                'description' => 'Accept it, reject it, or retire one already accepted — all three are the same write.',
                'request' => $this->object(['status' => ['type' => 'string', 'enum' => ['pending', 'accepted', 'rejected']]], ['status']),
                'response' => $this->one('IntakeLabel'),
            ],
            'capture-sessions.store' => [
                'tag' => 'Intake',
                'summary' => 'Start a phone capture',
                'description' => 'Answers with `pairing_url`, the signed link the phone loads and uploads through — the interface renders it as a QR code. Naming an attachment aims the session at replacing that file; such a session takes one photo and ends.',
                'status' => 201,
                'request' => $this->object(['attachment' => $uuid]),
                'response' => $this->one('CaptureSession'),
            ],
            'capture-sessions.show' => [
                'tag' => 'Intake',
                'summary' => 'Get a capture session',
                'description' => 'How a client waits for the phone.',
                'response' => $this->one('CaptureSession'),
            ],
            'capture-sessions.cancel' => [
                'tag' => 'Intake',
                'summary' => 'Cancel a capture session',
                'response' => $this->one('CaptureSession'),
            ],

            // ── Tasks ─────────────────────────────────────────────────────
            'workspaces.tasks.index' => [
                'tag' => 'Tasks',
                'summary' => 'List tasks',
                'description' => 'Reading an attachment creates a task per uploaded file, so filter by status and type rather than paging past them.',
                'query' => array_merge($listing, [
                    ['name' => 'status', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'type', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                ]),
                'response' => $this->page('Task'),
            ],
            'workspaces.tasks.show' => [
                'tag' => 'Tasks',
                'summary' => 'Get a task',
                'description' => 'How a client waits for work it started.',
                'response' => $this->one('Task'),
            ],
            'workspaces.tasks.store' => [
                'tag' => 'Tasks',
                'summary' => 'Start an export',
                'description' => 'Queued. Read the task until it finishes, then fetch the file.',
                'status' => 202,
                'validates' => true,
                'response' => $this->one('Task'),
            ],
            'workspaces.tasks.reextract' => [
                'tag' => 'Tasks',
                'summary' => 'Re-read every attachment',
                'description' => 'Queued. One task stands for the whole sweep, and its progress is counted in attachments rather than in queued jobs.',
                'status' => 202,
                'validates' => true,
                'response' => $this->one('Task'),
            ],
            'workspaces.tasks.retry' => ['tag' => 'Tasks', 'summary' => 'Retry a failed task', 'validates' => true, 'response' => $this->one('Task')],
            'workspaces.tasks.download' => [
                'tag' => 'Tasks',
                'summary' => 'Download an export',
                'description' => 'Every reason there might not be a file — wrong kind of task, still running, failed, pruned off the disk — answers 404. From a client\'s side there either is one or there is not.',
                'binary' => true,
            ],
            'workspaces.activity.index' => [
                'tag' => 'Tasks',
                'summary' => 'Read the audit trail',
                'description' => 'Filter by event and log name, which is what turns a feed into an answer to a question: everything deleted last week, everything one person did.',
                'query' => array_merge($listing, [
                    ['name' => 'event', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                    ['name' => 'log_name', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'string']],
                ]),
                'response' => $this->page('Activity'),
            ],
        ];
    }
}
