<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Document;
use App\Support\TableSort;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * The order a listing is read in.
 *
 * Asserted against the SQL rather than against rows, because the guarantee is
 * about the query and not about what any one dataset happens to come back as.
 * A tied order is not *wrong* on a given set of rows — the database is free to
 * arrange them however it likes, and on a small table it will usually pick the
 * same arrangement twice. It is free not to, and that is the whole defect:
 * `paginate()` asks a fresh question per page, and an order that does not fully
 * determine the answer lets a row land on two pages or on none.
 *
 * Testing it through a listing would therefore have proved nothing. Dropping the
 * tiebreaker leaves the paging tests in `DocumentSortingTest` green.
 */
class TableSortTest extends TestCase
{
    public function test_every_order_ends_in_the_tiebreaker()
    {
        $sort = TableSort::of(['date' => 'documents.document_date'], 'date', 'asc');

        $query = Document::query();
        $sort->apply($query, 'documents.id');

        $this->assertStringEndsWith(
            'order by `documents`.`document_date` asc, `documents`.`id` asc',
            $query->toSql(),
        );
    }

    public function test_a_key_outside_the_whitelist_never_reaches_the_query()
    {
        $request = Request::create('/', 'GET', [
            'sort' => 'ocr_text); drop table documents;--',
            'direction' => 'desc',
        ]);

        $sort = TableSort::fromRequest($request, ['title' => 'documents.title'], 'title');

        $query = Document::query();
        $sort->apply($query, 'documents.id');

        $this->assertSame('title', $sort->key);
        $this->assertStringEndsWith(
            'order by `documents`.`title` desc, `documents`.`id` desc',
            $query->toSql(),
        );
    }

    public function test_an_order_can_span_several_columns_before_the_tiebreaker()
    {
        $sort = TableSort::of(
            ['who' => ['users.name', 'users.email']],
            'who',
            'asc',
        );

        $query = Document::query();
        $sort->apply($query, 'documents.id');

        $this->assertStringEndsWith(
            'order by `users`.`name` asc, `users`.`email` asc, `documents`.`id` asc',
            $query->toSql(),
        );
    }

    /**
     * The direction a column starts in, for the URLs people type themselves.
     * The interface always sends both halves.
     */
    public function test_a_missing_direction_belongs_to_the_default_column_only()
    {
        $columns = ['created_at' => 'documents.created_at', 'title' => 'documents.title'];

        $withoutSort = TableSort::fromRequest(Request::create('/'), $columns, 'created_at', 'desc');
        $this->assertSame('desc', $withoutSort->direction);

        $anotherColumn = TableSort::fromRequest(
            Request::create('/', 'GET', ['sort' => 'title']),
            $columns,
            'created_at',
            'desc',
        );
        $this->assertSame('asc', $anotherColumn->direction);
    }

    public function test_an_unrecognised_direction_is_ignored_rather_than_refused()
    {
        $sort = TableSort::fromRequest(
            Request::create('/', 'GET', ['sort' => 'title', 'direction' => 'sideways']),
            ['title' => 'documents.title'],
            'title',
            'asc',
        );

        $this->assertSame('asc', $sort->direction);
    }
}
