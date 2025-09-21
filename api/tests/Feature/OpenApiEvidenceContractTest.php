<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Guards the Evidence contract in OpenAPI:
 * - Evidence schemas and examples expose `size` only.
 * - No `size_bytes` anywhere in the served spec.
 */
final class OpenApiEvidenceContractTest extends TestCase
{
    public function test_openapi_json_evidence_schema_uses_size_only(): void
    {
        $res = $this->getJson('/api/openapi.json');
        $res->assertOk();

        /** @var array<string,mixed> $spec */
        $spec = $res->json();

        // components.schemas.Evidence
        $evidence = $spec['components']['schemas']['Evidence'] ?? null;
        $this->assertIsArray($evidence, 'Evidence schema missing');
        $props = $evidence['properties'] ?? null;
        $this->assertIsArray($props, 'Evidence properties missing');

        $this->assertArrayHasKey('size', $props, 'Evidence.size not found');
        $this->assertSame('integer', $props['size']['type'] ?? null, 'Evidence.size must be integer');
        $this->assertArrayNotHasKey('size_bytes', $props, 'Evidence must not expose size_bytes');

        // components.schemas.EvidenceCreateResponse
        $create = $spec['components']['schemas']['EvidenceCreateResponse'] ?? null;
        $this->assertIsArray($create, 'EvidenceCreateResponse schema missing');
        $cprops = $create['properties'] ?? null;
        $this->assertIsArray($cprops, 'EvidenceCreateResponse properties missing');
        $this->assertArrayHasKey('size', $cprops, 'CreateResponse.size not found');
        $this->assertArrayNotHasKey('size_bytes', $cprops, 'CreateResponse must not expose size_bytes');

        // paths./evidence.get 200 example payload sanity
        $paths = $spec['paths'] ?? [];
        $evGet = $paths['/evidence']['get']['responses']['200']['content']['application/json']['examples']['example']['value'] ?? null;
        if (is_array($evGet)) {
            $this->assertIsArray($evGet['data'] ?? null, 'Evidence list example missing data array');
            $item = $evGet['data'][0] ?? null;
            if (is_array($item)) {
                $this->assertArrayHasKey('size', $item, 'Evidence list example item missing size');
                $this->assertArrayNotHasKey('size_bytes', $item, 'Evidence list example item must not include size_bytes');
            }
        }
    }

    public function test_openapi_json_contains_no_size_bytes_anywhere(): void
    {
        $res = $this->get('/api/openapi.json');
        $res->assertOk();

        $raw = (string) $res->getContent();
        $this->assertStringNotContainsString('size_bytes', $raw, 'OpenAPI spec leaked size_bytes');
    }
}
