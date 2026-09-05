<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Contracts\Database\Query\Expression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The order a listing is read in, chosen by whoever is reading it.
 *
 * Every table in the application used to be ordered by a decision made in a
 * controller, with no way for a reader to change it. This carries that choice
 * from the query string to the query, for all of them, in one place.
 *
 * Two things it is deliberate about:
 *
 * **Nothing user-supplied reaches `orderBy`.** The caller passes a whitelist of
 * the columns its screen actually offers, and a key outside it is not an error
 * — it falls back to the listing's default. A bookmark to a column that has
 * since been renamed should open the page it names, not a 422.
 *
 * **Every order ends in a tiebreaker.** That is not a nicety. `paginate()` runs
 * a fresh query per page with a different `OFFSET`, so an order that leaves ties
 * — a date many documents share, a status most tasks are in — lets a row appear
 * on two pages, or on none, while somebody clicks through. The listing looks
 * like it is losing and duplicating records and nothing in the code looks wrong.
 * Appending a unique column removes the ambiguity the database was resolving for
 * itself.
 */
final readonly class TableSort
{
    /**
     * @param string $key The chosen column, named as the interface names it.
     * @param 'asc'|'desc' $direction Which way that column is read.
     * @param list<string|Expression> $columns The SQL columns `$key` orders by, applied in order.
     */
    private function __construct(
        public string $key,
        public string $direction,
        private array $columns,
    ) {}

    /**
     * Resolve the requested order against what this listing offers.
     *
     * A missing or unrecognised direction leaves the default column in the
     * direction the listing was designed around — newest activity first — and
     * starts any other column ascending, which is what a hand-typed `?sort=name`
     * should do. The interface always sends both, so this only governs a URL
     * somebody wrote themselves.
     *
     * @param Request $request The incoming request, read for `sort` and `direction`.
     * @param array<string, string|Expression|list<string|Expression>> $columns Public sort key to the SQL column(s) it orders by.
     * @param string $default The key to fall back to; must exist in $columns.
     * @param 'asc'|'desc' $defaultDirection The direction that default column reads best in.
     *
     * @return self The resolved order, ready to apply.
     */
    public static function fromRequest(
        Request $request,
        array $columns,
        string $default,
        string $defaultDirection = 'asc',
    ): self {
        $requestedKey = $request->query('sort');
        $key = is_string($requestedKey) && array_key_exists($requestedKey, $columns)
            ? $requestedKey
            : $default;

        $requestedDirection = $request->query('direction');
        $direction = match (is_string($requestedDirection) ? mb_strtolower($requestedDirection) : '') {
            'asc' => 'asc',
            'desc' => 'desc',
            default => $key === $default ? $defaultDirection : 'asc',
        };

        return self::of($columns, $key, $direction);
    }

    /**
     * The order a listing falls back to when nobody has asked for one.
     *
     * Separate from `fromRequest()` so an action can hold a default order of its
     * own without a request in hand. A listing that reaches the database with no
     * order is the defect this class exists for; there is no way to construct
     * one that has none.
     *
     * @param array<string, string|Expression|list<string|Expression>> $columns Public sort key to the SQL column(s) it orders by.
     * @param string $key The column to order by; must exist in $columns.
     * @param 'asc'|'desc' $direction Which way that column is read.
     *
     * @return self The given order.
     */
    public static function of(array $columns, string $key, string $direction): self
    {
        $column = $columns[$key];

        return new self($key, $direction, is_array($column) ? $column : [$column]);
    }

    /**
     * Order the query, then settle the ties.
     *
     * @param Builder<covariant Model> $query The query being ordered.
     * @param string $tiebreaker A column unique within the result set, usually the table's primary key.
     *
     * @return void No return value; the builder is ordered in place.
     */
    public function apply(Builder $query, string $tiebreaker): void
    {
        foreach ($this->columns as $column) {
            $query->orderBy($column, $this->direction);
        }

        $query->orderBy($tiebreaker, $this->direction);
    }

    /**
     * The chosen order, for the page to render its controls from.
     *
     * @return array{key: string, direction: string} The active column and direction.
     */
    public function toArray(): array
    {
        return ['key' => $this->key, 'direction' => $this->direction];
    }
}
