<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class OpenApiSpecTest extends TestCase
{
    public function test_yaml_served_with_expected_headers_and_content(): void
    {
        $res = $this->get('/openapi.yaml');
        $res->assertOk();

        $ct = (string) $res->headers->get('content-type');
        $this->assertSame('application/yaml', strtolower(trim(explode(';', $ct)[0])));

        $etag = (string) $res->headers->get('ETag');
        $this->assertNotSame('', $etag);
        $this->assertStringStartsWith('"sha256:', $etag);

        $cc = (string) $res->headers->get('Cache-Control');
        $this->assertCacheDirectives($cc);

        $xcto = (string) $res->headers->get('X-Content-Type-Options');
        $this->assertSame('nosniff', strtolower($xcto));

        $vary = (string) $res->headers->get('Vary');
        $this->assertSame('Accept-Encoding', $vary);

        $lm = (string) $res->headers->get('Last-Modified');
        $this->assertNotSame('', $lm);

        $res->assertSee('openapi: 3.1.0', false);

        // Conditional GET should return 304
        $res304 = $this->get('/openapi.yaml', ['If-None-Match' => $etag]);
        $this->assertSame(304, $res304->getStatusCode());
        $this->assertSame($etag, (string) $res304->headers->get('ETag'));
    }

    public function test_json_served_with_expected_headers_and_content(): void
    {
        $res = $this->get('/openapi.json');
        $res->assertOk();

        $ct = (string) $res->headers->get('content-type');
        $this->assertSame('application/json', strtolower(trim(explode(';', $ct)[0])));

        $etag = (string) $res->headers->get('ETag');
        $this->assertNotSame('', $etag);
        $this->assertStringStartsWith('"sha256:', $etag);

        $cc = (string) $res->headers->get('Cache-Control');
        $this->assertCacheDirectives($cc);

        $xcto = (string) $res->headers->get('X-Content-Type-Options');
        $this->assertSame('nosniff', strtolower($xcto));

        $vary = (string) $res->headers->get('Vary');
        $this->assertSame('Accept-Encoding', $vary);

        $lm = (string) $res->headers->get('Last-Modified');
        $this->assertNotSame('', $lm);

        $this->assertJson($res->getContent());

        // Conditional GET should return 304
        $res304 = $this->get('/openapi.json', ['If-None-Match' => $etag]);
        $this->assertSame(304, $res304->getStatusCode());
        $this->assertSame($etag, (string) $res304->headers->get('ETag'));
    }

    /**
     * Assert Cache-Control contains no-store and max-age=0, order-agnostic.
     */
    private function assertCacheDirectives(string $ccRaw): void
    {
        $cc = strtolower($ccRaw);
        $parts = array_filter(array_map('trim', explode(',', $cc)));
        $directives = [];
        foreach ($parts as $p) {
            if ($p === '') {
                continue;
            }
            if (str_contains($p, '=')) {
                [$k, $v] = array_map('trim', explode('=', $p, 2));
                $directives[$k] = $v;
            } else {
                $directives[$p] = true;
            }
        }

        $this->assertArrayHasKey('no-store', $directives);
        $this->assertArrayHasKey('max-age', $directives);
        $this->assertSame('0', (string) $directives['max-age']);
    }
}

