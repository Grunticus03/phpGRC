<?php

declare(strict_types=1);

namespace Tests\Feature\OpenApi;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

final class OpenApiParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_json_parity_with_yaml_if_endpoint_exists(): void
    {
        // Always fetch YAML.
        $yamlRes = $this->get('/openapi.yaml');
        $yamlRes->assertStatus(200);
        $yamlText = (string) $yamlRes->getContent();
        $this->assertNotSame('', $yamlText);

        // Try JSON; skip test if not present (optional endpoint).
        $jsonRes = $this->get('/openapi.json');
        if ($jsonRes->getStatusCode() === 404) {
            $this->markTestSkipped('/api/openapi.json not implemented');
        }

        $jsonRes->assertStatus(200);

        $ctype = (string) $jsonRes->headers->get('Content-Type', '');
        $this->assertTrue(str_starts_with($ctype, 'application/json'));

        // Parse both and compare core anchors.
        /** @var array<string,mixed> $yaml */
        $yaml = Yaml::parse($yamlText);
        /** @var array<string,mixed> $json */
        $json = (array) json_decode((string) $jsonRes->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // Check version and info.title parity.
        $this->assertSame($yaml['openapi'] ?? null, $json['openapi'] ?? null);
        $this->assertSame($yaml['info']['title'] ?? null, $json['info']['title'] ?? null);

        // Servers URLs should match.
        $yamlServers = array_map(
            static fn ($s) => is_array($s) ? ($s['url'] ?? null) : null,
            is_array($yaml['servers'] ?? null) ? $yaml['servers'] : []
        );
        $jsonServers = array_map(
            static fn ($s) => is_array($s) ? ($s['url'] ?? null) : null,
            is_array($json['servers'] ?? null) ? $json['servers'] : []
        );
        $this->assertSame($yamlServers, $jsonServers);

        // Spot-check a known path exists in both if any paths exist.
        if (isset($yaml['paths']) && is_array($yaml['paths']) && $yaml['paths'] !== []) {
            $firstPath = array_key_first($yaml['paths']);
            $this->assertTrue(isset($json['paths'][$firstPath]));
        }
    }
}

