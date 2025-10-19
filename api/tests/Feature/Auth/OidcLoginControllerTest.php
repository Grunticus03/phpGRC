<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Contracts\Auth\OidcAuthenticatorContract;
use App\Models\IdpProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class OidcLoginControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_oidc_login_issues_token_and_replaces_existing_tokens(): void
    {
        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'oidc-primary',
            'name' => 'OIDC Primary',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://sso.example.test',
                'client_id' => 'client-123',
                'client_secret' => 'secret',
                'jit' => [
                    'create_users' => true,
                    'default_roles' => [],
                    'role_templates' => [],
                ],
            ],
        ]);

        $user = User::factory()->create([
            'email' => 'oidc-user@example.test',
        ]);

        $user->createToken('spa', ['*']);
        $existingTokenId = PersonalAccessToken::query()->where('tokenable_id', $user->id)->where('name', 'spa')->value('id');

        $stub = new class($user) implements OidcAuthenticatorContract
        {
            public function __construct(private readonly User $user) {}

            /**
             * @param  array<string,mixed>  $input
             */
            public function authenticate(IdpProvider $provider, array $input, \Illuminate\Http\Request $request): User
            {
                return $this->user;
            }
        };

        $this->app->instance(OidcAuthenticatorContract::class, $stub);

        $payload = [
            'provider' => $provider->id,
            'code' => 'auth-code',
            'redirect_uri' => 'https://spa.example.test/callback',
        ];

        $response = $this->postJson('/auth/oidc/login', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'ok',
                'token',
                'user' => ['id', 'email', 'roles'],
            ])
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.email', $user->email);

        $tokens = PersonalAccessToken::query()
            ->where('tokenable_id', $user->id)
            ->where('name', 'spa')
            ->get();

        $this->assertCount(1, $tokens);
        $this->assertNotSame($existingTokenId, $tokens->first()->id);
    }

    public function test_oidc_login_returns_forbidden_when_provider_disabled(): void
    {
        IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'oidc-disabled',
            'name' => 'OIDC Disabled',
            'driver' => 'oidc',
            'enabled' => false,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://sso.disabled.test',
                'client_id' => 'client',
                'client_secret' => 'secret',
                'jit' => [
                    'create_users' => true,
                    'default_roles' => [],
                    'role_templates' => [],
                ],
            ],
        ]);

        $response = $this->postJson('/auth/oidc/login', [
            'provider' => 'oidc-disabled',
            'code' => 'auth-code',
            'redirect_uri' => 'https://spa.example.test/callback',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'IDP_PROVIDER_DISABLED');
    }

    public function test_oidc_login_validates_required_fields(): void
    {
        $response = $this->postJson('/auth/oidc/login', [
            'provider' => 'missing-provider',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }
}
