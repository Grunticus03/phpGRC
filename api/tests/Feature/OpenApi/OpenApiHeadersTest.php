<?php

declare(strict_types=1);

namespace Tests\Feature\OpenApi;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OpenApiHeadersTest extends TestCase
{
    use RefreshDatabase;

    public function test_yaml_headers_match_contract(): void
    {
        $res = $this->get('/openapi.yaml');

        $res->assertStatus(200);

        $ctype = (string) $res->headers->get('Content-Type', '');
        $this->assertSame('application/yaml', $ctype);

        $etag = (string) $res->headers->get('ETag', '');
        $this->assertNotSame('', $etag);
        $this->assertMatchesRegularExpression('/^"sha256:[0-9a-f]{64}"$/', $etag);

        $cc = (string) $res->headers->get('Cache-Control', '');
        $this->assertStringContainsString('no-store', $cc);
        $this->assertStringContainsString('max-age=0', $cc);

        $xcto = (string) $res->headers->get('X-Content-Type-Options', '');
        $this->assertSame('nosniff', strtolower($xcto));
    }
}

