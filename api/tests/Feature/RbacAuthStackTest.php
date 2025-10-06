<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class RbacAuthStackTest extends TestCase
{
    public function test_guest_can_access_rbac_route_when_auth_not_required(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.require_auth', false);

        $res = $this->getJson('/rbac/roles');

        $res->assertStatus(200);
        $res->assertJson(fn ($j) => $j->where('ok', true)->has('roles'));
    }

    public function test_guest_blocked_when_auth_required(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.require_auth', true);

        $res = $this->getJson('/rbac/roles');

        $res->assertStatus(401);
    }

    public function test_rbac_disabled_bypasses_enforcement(): void
    {
        config()->set('core.rbac.enabled', false);
        config()->set('core.rbac.require_auth', true); // irrelevant when disabled

        $res = $this->getJson('/rbac/roles');

        $res->assertStatus(200);
        $res->assertJson(fn ($j) => $j->where('ok', true)->has('roles'));
    }
}
