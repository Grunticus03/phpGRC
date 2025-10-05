<?php

declare(strict_types=1);

use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\TestCase;

#[CoversNothing]
final class SettingsPolicyTest extends TestCase
{
    public function test_settings_policy_stub(): void
    {
        $this->markTestSkipped('Policy checks deferred until RBAC enforcement wiring.');
    }
}
