<?php

declare(strict_types=1);

namespace Tests\Feature\Docs;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OpenApiAugmentationTest extends TestCase
{
    use RefreshDatabase;

    private string $specDir;
    private string $yamlPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->specDir  = base_path('docs/api');
        $this->yamlPath = $this->specDir . '/openapi.yaml';

        if (!is_dir($this->specDir)) {
            mkdir($this->specDir, 0777, true);
        }

        // Minimal spec with the paths we mutate at render-time.
        $yaml = <<<YAML
openapi: 3.1.0
info:
  title: phpGRC API
  version: 0.4.7
paths:
  /dashboard/kpis:
    get:
      summary: KPIs
      responses:
        '200':
          description: OK
  /metrics/dashboard:
    get:
      summary: KPIs alias
      responses:
        '200':
          description: OK
  /audit/export.csv:
    get:
      summary: Export audit CSV
      responses:
        '200':
          description: OK
  /evidence:
    post:
      summary: Upload evidence
      responses:
        '201':
          description: Created
YAML;

        file_put_contents($this->yamlPath, $yaml);
    }

    protected function tearDown(): void
    {
        @unlink($this->yamlPath);
        // Do not remove directory to avoid race with parallel tests.
        parent::tearDown();
    }

    public function test_json_contains_injected_validation_and_capability_responses(): void
    {
        $resp = $this->get('/openapi.json')->assertOk();
        $doc  = $resp->json();

        // Components exist
        $this->assertIsArray($doc['components'] ?? null);
        $schemas = $doc['components']['schemas'] ?? [];
        $responses = $doc['components']['responses'] ?? [];

        $this->assertArrayHasKey('ValidationError', $schemas);
        $this->assertArrayHasKey('CapabilityError', $schemas);
        $this->assertArrayHasKey('ValidationFailed', $responses);
        $this->assertArrayHasKey('CapabilityDisabled', $responses);

        // 422 on KPIs endpoints
        $this->assertSame(
            '#/components/responses/ValidationFailed',
            $doc['paths']['/dashboard/kpis']['get']['responses']['422']['$ref'] ?? null
        );
        $this->assertSame(
            '#/components/responses/ValidationFailed',
            $doc['paths']['/metrics/dashboard']['get']['responses']['422']['$ref'] ?? null
        );

        // 403 on capability-gated endpoints
        $this->assertSame(
            '#/components/responses/CapabilityDisabled',
            $doc['paths']['/audit/export.csv']['get']['responses']['403']['$ref'] ?? null
        );
        $this->assertSame(
            '#/components/responses/CapabilityDisabled',
            $doc['paths']['/evidence']['post']['responses']['403']['$ref'] ?? null
        );
    }

    public function test_yaml_endpoint_emits_mutated_spec(): void
    {
        $resp = $this->get('/openapi.yaml')->assertOk();
        $text = (string) $resp->getContent();

        $this->assertStringContainsString('ValidationFailed', $text);
        $this->assertStringContainsString('/dashboard/kpis:', $text);
        $this->assertStringContainsString("'422':", $text);
        $this->assertStringContainsString('CapabilityDisabled', $text);
        $this->assertStringContainsString("/audit/export.csv:\n", $text);
        $this->assertStringContainsString("'403':", $text);
    }
}

