<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SetupStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_status_returns_ok_with_checks(): void
    {
        $res = $this->getJson('/api/setup/status');
        $res->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonStructure(['ok', 'setupComplete', 'nextStep', 'checks' => [
                'db_config','app_key','schema_init','admin_seed','admin_mfa_verify','smtp','idp','branding'
            ]]);
    }
}

