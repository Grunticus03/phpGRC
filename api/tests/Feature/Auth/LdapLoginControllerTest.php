<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Contracts\Auth\LdapAuthenticatorContract;
use App\Models\IdpProvider;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Tests\TestCase;

final class LdapLoginControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_ldap_login_issues_token_and_replaces_existing_tokens(): void
    {
        config()->set('core.auth.bruteforce.enabled', false);

        $provider = IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'ldap-primary',
            'name' => 'LDAP Primary',
            'driver' => 'ldap',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'host' => 'ldap.example.test',
                'base_dn' => 'dc=example,dc=test',
                'bind_dn' => 'cn=service,dc=example,dc=test',
                'bind_password' => 'secret',
                'bind_strategy' => 'service',
                'user_filter' => '(uid={{username}})',
                'username_attribute' => 'uid',
                'email_attribute' => 'mail',
                'name_attribute' => 'cn',
            ],
        ]);

        $user = User::factory()->create([
            'email' => 'ldap-user@example.test',
        ]);

        $user->createToken('spa', ['*']);
        $existingTokenId = PersonalAccessToken::query()
            ->where('tokenable_id', $user->id)
            ->where('name', 'spa')
            ->value('id');

        $stub = new class($user) implements LdapAuthenticatorContract
        {
            public function __construct(private readonly User $user) {}

            /**
             * @param  array<string,mixed>  $input
             */
            public function authenticate(IdpProvider $provider, array $input, Request $request): User
            {
                return $this->user;
            }
        };

        $this->app->instance(LdapAuthenticatorContract::class, $stub);

        $response = $this->postJson('/auth/ldap/login', [
            'provider' => $provider->id,
            'username' => 'jdoe',
            'password' => 'secret',
        ]);

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

    public function test_ldap_login_returns_forbidden_when_provider_disabled(): void
    {
        config()->set('core.auth.bruteforce.enabled', false);

        IdpProvider::query()->create([
            'id' => (string) Str::ulid(),
            'key' => 'ldap-disabled',
            'name' => 'LDAP Disabled',
            'driver' => 'ldap',
            'enabled' => false,
            'evaluation_order' => 1,
            'config' => [
                'host' => 'ldap.example.test',
                'base_dn' => 'dc=example,dc=test',
                'bind_dn' => 'cn=service,dc=example,dc=test',
                'bind_password' => 'secret',
                'bind_strategy' => 'service',
                'user_filter' => '(uid={{username}})',
                'username_attribute' => 'uid',
                'email_attribute' => 'mail',
                'name_attribute' => 'cn',
            ],
        ]);

        $response = $this->postJson('/auth/ldap/login', [
            'provider' => 'ldap-disabled',
            'username' => 'jdoe',
            'password' => 'secret',
        ]);

        $response->assertStatus(403)
            ->assertJsonPath('code', 'IDP_PROVIDER_DISABLED');
    }

    public function test_ldap_login_validates_required_fields(): void
    {
        config()->set('core.auth.bruteforce.enabled', false);

        $response = $this->postJson('/auth/ldap/login', [
            'provider' => 'missing-provider',
            'username' => '',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username', 'password']);
    }
}
