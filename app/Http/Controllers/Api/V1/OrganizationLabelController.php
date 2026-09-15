<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What goes on the labels stuck to the physical archive.
 */
class OrganizationLabelController extends Controller
{
    /**
     * List the nodes that carry a printable label, with what their code points at.
     *
     * The interface returns a rendered PNG here, because it is about to put it
     * on a page and send it to a printer. This returns the URL that image
     * encodes instead: a client making labels has its own idea of size,
     * margins and error correction, and a base64 PNG inside JSON is the one
     * shape it cannot adjust.
     *
     * @param Request $request The incoming request, read for an optional `level_id` filter.
     * @param OrganizationScheme $scheme The scheme whose labels are listed.
     *
     * @return JsonResponse Each labelled node's path, level and target URL.
     *
     * @throws AuthorizationException If the token's user cannot view $scheme.
     */
    public function index(Request $request, OrganizationScheme $scheme): JsonResponse
    {
        $this->authorize('view', $scheme);

        $levelId = $request->query('level_id');

        $nodes = OrganizationNode::query()
            ->whereHas(
                'level',
                fn (Builder $level) => $level
                    ->where('scheme_id', $scheme->id)
                    ->where('has_printable_label', true)
                    ->when(is_string($levelId), fn (Builder $query) => $query->where('id', $levelId)),
            )
            ->with('level')
            ->oldest()
            ->get();

        return new JsonResponse([
            'data' => $nodes->map(fn (OrganizationNode $node): array => [
                'node_id' => $node->id,
                'path' => $node->path(),
                'level' => $node->level->name,
                'url' => route('organization.schemes.storage', [
                    'scheme' => $scheme->id,
                    'node' => $node->id,
                ]),
            ])->values()->all(),
        ]);
    }
}
