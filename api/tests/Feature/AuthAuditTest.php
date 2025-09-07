<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

final class AuthAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_logout_totp_emit_audit_events(): void
    {
        // Login
        $this->postJson('/api/auth/login')->assertOk();

        // Logout
        $this->postJson('/api/auth/logout')->assertNoContent();

        // TOTP enroll + verify
        $this->postJson('/api/auth/totp/enroll')->assertOk();
        $this->postJson('/api/auth/totp/verify', ['code' => '000000'])->assertOk();

        $actions = AuditEvent::query()->pluck('action')->all();
        // Order-insensitive check for required actions
        $this->assertContains('auth.login', $actions);
        $this->assertContains('auth.logout', $actions);
        $this->assertContains('auth.totp.enroll', $actions);
        $this->assertContains('auth.totp.verify', $actions);
    }

    public function test_break_glass_guard_logs_when_disabled(): void
    {
        Config::set('core.auth.break_glass.enabled', false);

        $this->postJson('/api/auth/break-glass')
            ->assertStatus(404)
            ->assertJson(['error' => 'BREAK_GLASS_DISABLED']);

        $row = AuditEvent::query()->latest('occurred_at')->first();
        $this->assertNotNull($row);
        $this->assertSame('auth.break_glass.guard', $row->action);
        $this->assertSame('AUTH', $row->category);
    }
}
