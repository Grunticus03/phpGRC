<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuditCsvExportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('core.audit.enabled', true);
        config()->set('core.rbac.enabled', false);
        config()->set('core.rbac.require_auth', false);

        // Seed deterministic rows
        $this->insertEvent([
            'occurred_at' => Carbon::parse('2025-02-01T00:00:00Z'),
            'category'    => 'RBAC',
            'action'      => 'rbac.user_role.attached',
            'entity_type' => 'user',
            'entity_id'   => '10',
        ]);

        $this->insertEvent([
            'occurred_at' => Carbon::parse('2025-02-01T00:00:05Z'),
            'category'    => 'AUTH',
            'action'      => 'auth.login',
            'entity_type' => 'user',
            'entity_id'   => '11',
        ]);
    }

    public function test_csv_export_headers_and_body(): void
    {
        $res = $this->get('/audit/export.csv?category=RBAC&order=asc');

        $res->assertStatus(200);
        $res->assertHeader('content-type', 'text/csv'); // exact, no charset
        $res->assertHeader('content-disposition');      // filename present
        $res->assertHeader('x-content-type-options', 'nosniff');

        $csv = $res->streamedContent();
        $this->assertIsString($csv);

        $this->assertStringContainsString('id,occurred_at,actor_id,action,category,entity_type,entity_id,ip,ua,meta_json', $csv);
        $this->assertStringContainsString('rbac.user_role.attached,RBAC,user', $csv);
    }

    public function test_csv_cursor_parity_byte_identical(): void
    {
        // Legacy path: get()
        config()->set('core.audit.csv_use_cursor', false);
        $resA = $this->get('/audit/export.csv?order=asc');
        $resA->assertStatus(200);
        $bodyA = $resA->streamedContent();

        // Cursor path: cursor()
        config()->set('core.audit.csv_use_cursor', true);
        $resB = $this->get('/audit/export.csv?order=asc');
        $resB->assertStatus(200);
        $bodyB = $resB->streamedContent();

        $this->assertSame($bodyA, $bodyB, 'CSV bytes must be identical between get() and cursor() iteration');
    }

    /**
     * @param array<string,mixed> $overrides
     */
    private function insertEvent(array $overrides = []): AuditEvent
    {
        $now = Carbon::now('UTC');

        $data = array_merge([
            'id'          => Str::ulid()->toBase32(),
            'occurred_at' => $now,
            'actor_id'    => null,
            'action'      => 'stub.event',
            'category'    => 'SYSTEM',
            'entity_type' => 'stub',
            'entity_id'   => '0',
            'ip'          => null,
            'ua'          => null,
            'meta'        => null,
            'created_at'  => $now,
        ], $overrides);

        /** @var AuditEvent $ev */
        $ev = AuditEvent::query()->create($data);
        return $ev;
    }
}

