<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\User;
use App\Support\AuthTokenCookie;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class AuthAuditTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_logout_totp_emit_audit_events(): void
    {
        config()->set('core.auth.bruteforce.enabled', false);

        $user = User::factory()->create([
            'email' => 'tester@example.com',
            'password' => Hash::make('secret123'),
        ]);

        // Login -> capture token and cookie
        $login = $this->postJson('/auth/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonStructure(['ok', 'token', 'user' => ['id', 'email']])
            ->assertCookie(AuthTokenCookie::name());

        $cookie = collect($login->headers->getCookies())
            ->firstWhere(fn ($c) => $c->getName() === AuthTokenCookie::name());
        $this->assertNotNull($cookie);

        // Logout via bearer token to ensure revocation and cookie clearing
        $this->withHeader('Authorization', 'Bearer '.(string) ($login->json('token') ?? ''))
            ->postJson('/auth/logout')
            ->assertNoContent();

        // TOTP enroll + verify (stubs remain open)
        $this->postJson('/auth/totp/enroll')->assertOk();
        $this->postJson('/auth/totp/verify', ['code' => '000000'])->assertOk();

        $actions = AuditEvent::query()->pluck('action')->all();
        $this->assertContains('auth.login', $actions);
        $this->assertContains('auth.logout', $actions);
        $this->assertContains('auth.totp.enroll', $actions);
        $this->assertContains('auth.totp.verify', $actions);
    }

    public function test_break_glass_guard_logs_when_disabled(): void
    {
        config(['core.auth.break_glass.enabled' => false]);

        $this->postJson('/auth/break-glass')
            ->assertStatus(404)
            ->assertJson(['error' => 'BREAK_GLASS_DISABLED']);

        $row = AuditEvent::query()->latest('occurred_at')->first();
        $this->assertNotNull($row);
        $this->assertSame('auth.break_glass.guard', $row->action);
        $this->assertSame('AUTH', $row->category);
    }
}
