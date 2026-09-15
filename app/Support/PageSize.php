<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;

/**
 * How many rows a listing returns for one request.
 *
 * The interface never asks — every page it renders takes the default — so this
 * exists for the API, where a client syncing an archive wants larger pages and
 * a client on a phone wants smaller ones.
 */
final class PageSize
{
    /**
     * What a listing returns when nobody asks, and what every Inertia page
     * takes. Changing it changes the interface too, which is the point: the
     * two should not quietly drift apart.
     */
    public const DEFAULT = 15;

    /**
     * The ceiling a request cannot raise.
     *
     * A page is assembled in memory with its relations eager-loaded, so an
     * unbounded `per_page` is a way to ask the application to hold the whole
     * archive at once. Anyone wanting all of it can page through.
     */
    public const MAX = 100;

    /**
     * Read the page size a request is asking for.
     *
     * Out-of-range values are clamped rather than rejected. The response says
     * what it actually used in `meta.per_page`, so a client asking for a
     * thousand rows is told it got a hundred instead of being handed a 422 for
     * something the archive is perfectly able to answer.
     *
     * @param Request $request The incoming request; its `per_page` query parameter is read.
     * @param int $default What to return when `per_page` is absent or not a number.
     *
     * @return int A page size between 1 and MAX.
     */
    public static function fromRequest(Request $request, int $default = self::DEFAULT): int
    {
        $requested = $request->query('per_page');

        if (!is_numeric($requested)) {
            return $default;
        }

        return max(1, min((int) $requested, self::MAX));
    }
}
