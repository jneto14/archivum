<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Password::defaults() always includes uncompromised(), which calls
        // the Have I Been Pwned API. Fake it so tests stay hermetic and never
        // depend on network access; an empty response means "not found",
        // i.e. the password is treated as not compromised.
        Http::fake([
            'api.pwnedpasswords.com/*' => Http::response('', 200),
        ]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (!Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
