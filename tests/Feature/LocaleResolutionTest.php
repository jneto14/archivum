<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Tests\TestCase;

class LocaleResolutionTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_users_locale_preference_is_used()
    {
        $user = User::factory()->create(['locale' => 'pt']);

        $this->actingAs($user)->get(route('profile.edit'));

        $this->assertSame('pt', App::getLocale());
    }

    public function test_accept_language_header_is_used_as_a_fallback_for_guests()
    {
        $this->withHeaders(['Accept-Language' => 'pt'])->get(route('login'));

        $this->assertSame('pt', App::getLocale());
    }

    public function test_falls_back_to_the_app_default_locale_when_no_preference_or_header_is_present()
    {
        $this->get(route('login'));

        $this->assertSame(config('app.locale'), App::getLocale());
    }

    public function test_an_unsupported_accept_language_falls_back_to_the_app_default_locale()
    {
        $this->withHeaders(['Accept-Language' => 'de'])->get(route('login'));

        $this->assertSame(config('app.locale'), App::getLocale());
    }
}
