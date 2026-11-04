<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    public function test_backend_health(): void
    {
        $this->getJson('/api/v1/health')->assertOk();
    }
}
