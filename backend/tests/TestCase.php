<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Keep test compilation independent of container-owned runtime caches.
        $compiled = sys_get_temp_dir().'/passover-test-views-'.getmypid();
        if (! is_dir($compiled)) {
            mkdir($compiled, 0700, true);
        }
        config()->set('view.compiled', $compiled);
    }
}
