<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class AuditPolicyTest extends TestCase
{
    public function test_audit_policy_stub(): void
    {
        $this->markTestSkipped('Policy checks deferred until RBAC enforcement wiring.');
    }
}
