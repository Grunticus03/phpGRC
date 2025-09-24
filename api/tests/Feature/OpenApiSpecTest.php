<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class OpenApiSpecTest extends TestCase
{
    public function test_yaml_served_with_expected_headers_and_content(): void
    {
        $res = $this->get('/api/openapi.yaml');
        $res->assertOk();

        $ct = (string) $res->headers->get('content-type');
        $this->assertSame('application/yaml', strtolower(trim(explode(';', $ct)[0])));

        $res->assertSee('openapi: 3.1.0', false);
    }

    public function test_json_served_with_expected_headers_and_content(): void
    {
        $res = $this->get('/api/openapi.json');
        $res->assertOk();

        $ct = (string) $res->headers->get('content-type');
        $this->assertSame('application/json', strtolower(trim(explode(';', $ct)[0])));

        $this->assertJson($res->getContent());
    }
}

