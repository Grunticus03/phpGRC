<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class OpenApiSpecTest extends TestCase
{
    public function test_yaml_served_with_expected_headers_and_content(): void
    {
        $res = $this->get('/api/openapi.yaml');

        $res->assertStatus(200);
        $res->assertHeader('content-type', 'application/yaml');
        $res->assertSee('openapi: 3.1.0', false);
        $res->assertSee('/health:', false);
        $res->assertSee('paths:', false);
    }
}
