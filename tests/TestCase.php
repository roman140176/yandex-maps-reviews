<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Sanctum only puts a request through the session guard when it looks
        // like it came from the SPA — which it decides from Origin/Referer. A
        // browser always sends those for same-origin XHR; the test client does
        // not, so it is spelled out here rather than in every test.
        $this->withHeaders([
            'Origin' => config('app.url'),
            'Referer' => config('app.url').'/',
        ]);
    }
}
