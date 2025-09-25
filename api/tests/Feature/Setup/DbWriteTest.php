<?php

declare(strict_types=1);

namespace Tests\Feature\Setup;

use Tests\TestCase;

final class DbWriteTest extends TestCase
{
    public function test_db_test_rejects_invalid_connection(): void
    {
        $payload = [
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'port'     => 3306,
            'database' => 'nope',
            'username' => 'nope',
            'password' => 'nope',
        ];

        $res = $this->postJson('/setup/db/test', $payload);
        $res->assertStatus(422)->assertJsonPath('code', 'DB_CONFIG_INVALID');
    }

    public function test_db_write_disabled_without_setup_enabled(): void
    {
        config()->set('core.setup.enabled', false);

        $payload = [
            'driver'   => 'mysql',
            'host'     => 'localhost',
            'database' => 'phpgrc',
            'username' => 'root',
            'password' => '',
        ];

        $res = $this->postJson('/setup/db/write', $payload);
        $res->assertStatus(400)->assertJsonPath('code', 'SETUP_STEP_DISABLED');
    }
}

