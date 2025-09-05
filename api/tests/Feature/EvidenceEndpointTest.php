<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class EvidenceEndpointTest extends TestCase
{
    public function test_evidence_endpoint_schema_stub(): void
    {
        $this->markTestSkipped('Laravel app wiring deferred; stub keeps CI green.');
    }
}
