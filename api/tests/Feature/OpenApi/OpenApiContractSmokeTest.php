<?php

declare(strict_types=1);

namespace Tests\Feature\OpenApi;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class OpenApiContractSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function serves_openapi_yaml_with_phase_version_and_expected_paths(): void
    {
        $res = $this->get('/openapi.yaml');
        $res->assertOk();

        $yaml = (string) $res->getContent();

        // Basic anchors
        $this->assertStringContainsString('openapi: 3.', $yaml);
        $this->assertStringContainsString('info:', $yaml);
        $this->assertStringContainsString('version: 0.4.6', $yaml);

        // Path anchors: allow specs that key paths with or without '/api' prefix.
        $this->assertTrue(
            $this->containsAny($yaml, ['/audit:', '/audit:']),
            'Expected an audit list path key.'
        );
        $this->assertTrue(
            $this->containsAny($yaml, ['/audit/export.csv:', '/audit/export.csv:']),
            'Expected an audit CSV export path key.'
        );

        // CSV content-type anchor
        $this->assertTrue(
            stripos($yaml, 'text/csv') !== false,
            'Expected CSV content-type to be documented.'
        );
    }

    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $n) {
            if (strpos($haystack, $n) !== false) {
                return true;
            }
        }
        return false;
    }
}
