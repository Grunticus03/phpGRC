<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class LoginControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_rejected_when_local_auth_disabled(): void
    {
        config()->set('core.auth.local.enabled', false);
        config()->set('core.auth.bruteforce.enabled', false);

        /** @var User $user */
        $user = User::factory()->create([
            'email' => 'disabled@example.test',
            'password' => bcrypt('secret'),
        ]);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'secret',
        ])
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'LOCAL_AUTH_DISABLED');
    }

    public function test_login_succeeds_when_local_enabled(): void
    {
        config()->set('core.auth.bruteforce.enabled', false);

        /** @var User $user */
        $user = User::factory()->create([
            'email' => 'enabled@example.test',
            'password' => bcrypt('secret'),
        ]);

        $this->postJson('/auth/login', [
            'email' => $user->email,
            'password' => 'secret',
        ])
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('user.email', $user->email)
            ->assertJsonStructure([
                'token',
                'user' => [
                    'id',
                    'email',
                    'roles',
                ],
            ]);
    }
}
