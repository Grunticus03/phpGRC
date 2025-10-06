<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class GatesTest extends TestCase
{
    public function test_gate_definitions_stub(): void
    {
        $this->markTestSkipped('Laravel container not booted in Phase 4; gate presence validated later.');
    }
}
