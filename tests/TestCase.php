<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\File;

abstract class TestCase extends BaseTestCase
{
    private ?string $livePath = null;

    protected function setUp(): void
    {
        parent::setUp();

        // Front-end assets are not built in CI; pages render without them.
        $this->withoutVite();

        // Live election results go to a folder of their own per test, not
        // into public/.
        $this->livePath = storage_path('framework/testing/live/'.bin2hex(random_bytes(6)));
        config(['elections.live_path' => $this->livePath, 'elections.min_interval' => 0]);
    }

    protected function tearDown(): void
    {
        if ($this->livePath !== null) {
            File::deleteDirectory($this->livePath);
        }

        parent::tearDown();
    }
}
