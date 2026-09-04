<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\PrintLabelsRequest;
use App\Models\OrganizationLevel;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationLabelController extends Controller
{
    /**
     * Render the printable labels for one node, or for every node of a level —
     * optionally only those under one parent, which is how a user prints the
     * labels for every drawer in one cabinet rather than for every drawer they own.
     *
     * The QR codes are embedded as data URIs: a print sheet that fetched a
     * hundred images would be at the mercy of the browser having finished
     * loading them when the print dialog opens.
     *
     * @param PrintLabelsRequest $request The incoming request, carrying either a node or a level (and optional parent).
     * @param OrganizationScheme $scheme The scheme whose labels are printed.
     *
     * @return Response The rendered print sheet.
     *
     * @throws AuthorizationException If the current user cannot view $scheme.
     * @throws ModelNotFoundException If the requested node, level or parent does not belong to $scheme.
     * @throws ValidationException If the level the labels were asked for does not carry printable labels.
     */
    public function index(PrintLabelsRequest $request, OrganizationScheme $scheme): Response
    {
        $this->authorize('view', $scheme);

        $nodes = $this->nodes($request, $scheme);

        return Inertia::render('organization/labels', [
            'scheme' => ['id' => $scheme->id, 'name' => $scheme->name],
            'labels' => $nodes
                ->map(fn (OrganizationNode $node) => [
                    'id' => $node->id,
                    'path' => $node->path(),
                    'level' => $node->level->name,
                    'qr' => $this->qrCodeFor($node, $scheme),
                ])
                ->values()
                ->all(),
        ]);
    }

    /**
     * Resolve which of the scheme's nodes are being labelled.
     *
     * @param PrintLabelsRequest $request The incoming request.
     * @param OrganizationScheme $scheme The scheme the nodes must belong to.
     *
     * @return Collection<int, OrganizationNode> The nodes to print, oldest first, each with its level loaded.
     *
     * @throws ModelNotFoundException If the requested node, level or parent does not belong to $scheme.
     * @throws ValidationException If the resolved level does not carry printable labels.
     */
    private function nodes(PrintLabelsRequest $request, OrganizationScheme $scheme): Collection
    {
        $nodeId = $request->validated('node_id');

        if ($nodeId !== null) {
            $node = $this->schemeNodes($scheme)->where('id', $nodeId)->with('level')->firstOrFail();

            $this->assertLevelIsLabelled($node->level);

            return Collection::make([$node]);
        }

        $level = $scheme->levels()->where('id', $request->validated('level_id'))->firstOrFail();

        $this->assertLevelIsLabelled($level);

        $parentId = $request->validated('parent_id');

        if ($parentId !== null) {
            // Checked for its own sake: without it, a parent id from another
            // scheme would silently print nothing rather than say why.
            $this->schemeNodes($scheme)->where('id', $parentId)->firstOrFail();
        }

        return $level->nodes()
            ->when($parentId !== null, fn ($query) => $query->where('parent_id', $parentId))
            ->with('level')
            ->orderBy('created_at')
            ->get();
    }

    /**
     * @param OrganizationScheme $scheme The scheme to scope nodes to.
     *
     * @return \Illuminate\Database\Eloquent\Builder<OrganizationNode> A query for the scheme's nodes.
     */
    private function schemeNodes(OrganizationScheme $scheme): \Illuminate\Database\Eloquent\Builder
    {
        return OrganizationNode::query()
            ->whereHas('level', fn ($query) => $query->where('scheme_id', $scheme->id));
    }

    /**
     * @param OrganizationLevel $level The level whose nodes were asked for.
     *
     * @return void No return value when the level carries labels.
     *
     * @throws ValidationException If $level does not carry printable labels.
     */
    private function assertLevelIsLabelled(OrganizationLevel $level): void
    {
        if (!$level->has_printable_label) {
            throw ValidationException::withMessages([
                'level_id' => __('organization.level_has_no_labels'),
            ]);
        }
    }

    /**
     * Build the QR code a label carries: the storage page with this node opened,
     * so scanning a box answers with what is in it.
     *
     * @param OrganizationNode $node The node being labelled.
     * @param OrganizationScheme $scheme The scheme the node belongs to.
     *
     * @return string The QR code as a data URI, ready for an <img src>.
     */
    private function qrCodeFor(OrganizationNode $node, OrganizationScheme $scheme): string
    {
        return (new Builder(
            writer: new PngWriter(),
            data: route('organization.schemes.storage', ['scheme' => $scheme->id, 'node' => $node->id]),
            size: 320,
            margin: 0,
        ))->build()->getDataUri();
    }
}
