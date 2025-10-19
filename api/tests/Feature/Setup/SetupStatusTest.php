<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use App\Models\IdpProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class SetupStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_returns_ok_with_checks(): void
    {
        $res = $this->getJson('/setup/status');
        $res->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['ok', 'setupComplete', 'nextStep', 'checks' => [
                'db_config', 'app_key', 'schema_init', 'admin_seed', 'admin_mfa_verify', 'smtp', 'idp', 'branding',
            ]]);
    }

    #[Test]
    public function idp_check_reflects_configured_provider(): void
    {
        IdpProvider::query()->create([
            'key' => 'primary',
            'name' => 'Primary',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://idp.example',
                'client_id' => 'client',
                'client_secret' => 'secret',
            ],
        ]);

        $res = $this->getJson('/setup/status');
        $res->assertStatus(200)
            ->assertJsonPath('checks.idp', true);
    }
}
