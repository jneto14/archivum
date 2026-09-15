<?php

declare(strict_types=1);

namespace Tests\Feature\Api\V1;

use App\Support\PageSize;
use Illuminate\Http\Request;
use Tests\TestCase;

class PageSizeTest extends TestCase
{
    public function test_a_request_that_does_not_ask_gets_the_default()
    {
        $this->assertSame(PageSize::DEFAULT, PageSize::fromRequest(Request::create('/')));
    }

    public function test_a_request_can_ask_for_a_page_size()
    {
        $this->assertSame(50, PageSize::fromRequest(Request::create('/?per_page=50')));
    }

    public function test_a_page_size_above_the_ceiling_is_clamped_rather_than_rejected()
    {
        $this->assertSame(PageSize::MAX, PageSize::fromRequest(Request::create('/?per_page=5000')));
    }

    public function test_a_page_size_below_one_is_clamped()
    {
        $this->assertSame(1, PageSize::fromRequest(Request::create('/?per_page=0')));
        $this->assertSame(1, PageSize::fromRequest(Request::create('/?per_page=-20')));
    }

    public function test_something_that_is_not_a_number_falls_back_to_the_default()
    {
        $this->assertSame(PageSize::DEFAULT, PageSize::fromRequest(Request::create('/?per_page=all')));
        $this->assertSame(PageSize::DEFAULT, PageSize::fromRequest(Request::create('/?per_page=')));
    }
}
