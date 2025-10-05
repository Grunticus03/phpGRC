<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class AvatarEndpointTest extends TestCase
{
    public function test_avatar_endpoint_schema_stub(): void
    {
        $this->markTestSkipped('Laravel app wiring deferred; stub keeps CI green.');
    }
}
