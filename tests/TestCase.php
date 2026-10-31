<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Http;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // No test talks to the internet (Google Maps links, push, ...): an unfaked request fails at once.
        Http::preventStrayRequests();
    }
}
