<?php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EvidenceAccessTest extends TestCase
{
    public function test_index_allows_anonymous_when_require_auth_false(): void
    {
        $this->enablePersistedRbac(requireAuth: false);

        $evidenceId = $this->insertEvidence();

        $response = $this->getJson('/evidence');

        $response->assertOk();
        $response->assertJson(['ok' => true]);

        $data = $response->json('data');
        $this->assertIsArray($data, 'Expected evidence data array in response');
        $this->assertNotEmpty($data, 'Evidence list should include seeded item');
        $this->assertSame($evidenceId, $data[0]['id'] ?? null, 'Expected seeded evidence ID to be returned');
    }

    public function test_index_requires_auth_when_require_auth_true(): void
    {
        $this->enablePersistedRbac(requireAuth: true);

        $this->insertEvidence();

        $response = $this->getJson('/evidence');

        $response->assertStatus(401);
        $response->assertJson([
            'ok'   => false,
            'code' => 'UNAUTHENTICATED',
        ]);
    }

    private function enablePersistedRbac(bool $requireAuth): void
    {
        Config::set('core.rbac.enabled', true);
        Config::set('core.rbac.mode', 'persist');
        Config::set('core.rbac.persistence', true);
        Config::set('core.rbac.require_auth', $requireAuth);
    }

    private function insertEvidence(): string
    {
        $userId = DB::table('users')->insertGetId([
            'name'           => 'Owner',
            'email'          => 'owner.' . Str::uuid() . '@example.test',
            'password'       => bcrypt('secret1234'),
            'remember_token' => Str::random(10),
            'created_at'     => now(),
            'updated_at'     => now(),
        ]);

        $evidenceId = 'ev_' . (string) Str::ulid();

        DB::table('evidence')->insert([
            'id'         => $evidenceId,
            'owner_id'   => $userId,
            'filename'   => 'report.pdf',
            'mime'       => 'application/pdf',
            'size_bytes' => 123,
            'sha256'     => str_repeat('a', 64),
            'version'    => 1,
            'bytes'      => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $evidenceId;
    }
}
