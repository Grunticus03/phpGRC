<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuditRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_command_respects_days(): void
    {
        // Older than 40 days
        $old = Carbon::now('UTC')->subDays(60)->startOfDay();

        // Newer than 40 days
        $new = Carbon::now('UTC')->subDays(10)->startOfDay();

        DB::table('audit_events')->insert([
            [
                'id' => Str::ulid()->toBase32(),
                'occurred_at' => $old,
                'actor_id' => 1,
                'action' => 'old',
                'category' => 'TEST',
                'entity_type' => 'e',
                'entity_id' => '1',
                'ip' => null,
                'ua' => null,
                'meta' => null,
                'created_at' => $old,
            ],
            [
                'id' => Str::ulid()->toBase32(),
                'occurred_at' => $new,
                'actor_id' => 1,
                'action' => 'new',
                'category' => 'TEST',
                'entity_type' => 'e',
                'entity_id' => '2',
                'ip' => null,
                'ua' => null,
                'meta' => null,
                'created_at' => $new,
            ],
        ]);

        $this->artisan('audit:purge --days=40')->assertExitCode(0);

        $rows = DB::table('audit_events')->pluck('action')->all();
        $this->assertSame(['new'], $rows);
    }
}
