<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Routing\Middleware\ThrottleRequests;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Rate limiting is covered by the framework; sharing one test IP must not
        // make unrelated feature tests influence each other.
        $this->withoutMiddleware(ThrottleRequests::class);
    }
}
