<?php

declare(strict_types=1);

namespace Tests\Feature\Rbac;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use App\Models\AuditEvent;
use App\Http\Controllers\Rbac\PolicyController;
use Illuminate\Http\Request;

final class PolicyOverrideUnknownRoleAuditTest extends TestCase
{
    use RefreshDatabase;

    private function setRuntimeConfig(array $overrides, string $mode): void
    {
        config()->set('core.audit.enabled', true);
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', $mode); // 'persist' or 'stub'
        config()->set('core.rbac.require_auth', false);
        config()->set('core.metrics.throttle.enabled', false);
        config()->set('core.rbac.policies.overrides', $overrides);
    }

    private function buildOnce(): void
    {
        /** @var PolicyController $controller */
        $controller = $this->app->make(PolicyController::class);
        $controller->effective(new Request());
        $controller->show(new Request());
    }

    public function test_emits_once_per_policy_per_boot_in_persist_mode(): void
    {
        $policy = 'core.metrics.view';
        $this->setRuntimeConfig([$policy => ['nosuchrole']], 'persist');

        $this->buildOnce();

        $events = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'rbac.policy.override.unknown_role')
            ->where('entity_id', $policy)
            ->get();

        $this->assertCount(1, $events, 'Expected one unknown_role audit per policy per boot');

        // No duplicate within same boot
        $this->buildOnce();

        $again = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'rbac.policy.override.unknown_role')
            ->where('entity_id', $policy)
            ->count();

        $this->assertSame(1, $again);
    }

    public function test_emits_for_each_policy_key_once_per_boot(): void
    {
        $p1 = 'core.audit.view';
        $p2 = 'core.rbac.view';
        $this->setRuntimeConfig([
            $p1 => ['missing_role'],
            $p2 => ['unknown_role'],
        ], 'persist');

        $this->buildOnce();

        $c1 = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'rbac.policy.override.unknown_role')
            ->where('entity_id', $p1)
            ->count();

        $c2 = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'rbac.policy.override.unknown_role')
            ->where('entity_id', $p2)
            ->count();

        $this->assertSame(1, $c1);
        $this->assertSame(1, $c2);
    }

    public function test_stub_mode_does_not_emit_unknown_role_audit(): void
    {
        $policy = 'rbac.user_roles.manage';
        $this->setRuntimeConfig([$policy => ['notarole']], 'stub');

        $this->buildOnce();

        $count = AuditEvent::query()
            ->where('category', 'RBAC')
            ->where('action', 'rbac.policy.override.unknown_role')
            ->where('entity_id', $policy)
            ->count();

        $this->assertSame(0, $count);
    }
}
