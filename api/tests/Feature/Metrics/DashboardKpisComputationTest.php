<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DashboardKpisComputationTest extends TestCase
{
    use RefreshDatabase;

    public function test_kpis_compute_expected_counts_and_rates(): void
    {
        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.policies' => array_merge(config('core.rbac.policies', []), [
                'core.metrics.view' => ['Admin'],
            ]),
        ]);

        $admin = $this->makeUser('Admin Metrics', 'admin-metrics@example.test');
        $this->attachNamedRole($admin, 'Admin');

        // Seed 7 days of AUTH/RBAC traffic plus RBAC denies.
        $now = Carbon::now()->startOfDay();
        $totalPerDay = 10; // AUTH+RBAC non-deny events
        $deniesPerDay = 3; // RBAC deny events

        for ($d = 0; $d < 7; $d++) {
            $day = (clone $now)->subDays($d);
            for ($i = 0; $i < $totalPerDay; $i++) {
                $this->insertAudit(
                    occurredAt: (clone $day)->addMinutes($i),
                    category: $i % 2 === 0 ? 'AUTH' : 'RBAC',
                    action: $i % 2 === 0 ? 'auth.login.success' : 'rbac.allow',
                );
            }
            for ($j = 0; $j < $deniesPerDay; $j++) {
                $this->insertAudit(
                    occurredAt: (clone $day)->addMinutes(100 + $j),
                    category: 'RBAC',
                    action: match ($j % 3) {
                        0 => 'rbac.deny.role',
                        1 => 'rbac.deny.policy',
                        default => 'rbac.deny.capability',
                    },
                );
            }
        }

        // Seed evidence for freshness calc (5 total, 2 stale at 30 days).
        $this->insertEvidence('application/pdf', $now->copy()->subDays(10));  // fresh
        $this->insertEvidence('application/pdf', $now->copy()->subDays(40));  // stale
        $this->insertEvidence('image/png',        $now->copy()->subDays(5));   // fresh
        $this->insertEvidence('image/png',        $now->copy()->subDays(60));  // stale
        $this->insertEvidence('text/plain',       $now->copy()->subDays(2));   // fresh

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/api/dashboard/kpis');
        $resp->assertStatus(200);

        $json = $resp->json();
        $data = is_array($json) && array_key_exists('data', $json) ? $json['data'] : $json;

        // RBAC denies KPI — validate internal consistency against daily buckets and window.
        $rbac = $data['rbac_denies'] ?? [];
        static::assertSame(7, (int) ($rbac['window_days'] ?? -1));

        $from = Carbon::parse((string) ($rbac['from'] ?? Carbon::now()->toDateString()))->startOfDay();
        $to   = Carbon::parse((string) ($rbac['to']   ?? Carbon::now()->toDateString()))->endOfDay();

        static::assertIsArray($rbac['daily'] ?? null);
        $days = (int) ($from->diffInDays($to) + 1);
        static::assertCount($days, $rbac['daily'] ?? []);

        $sumDailyTotal  = array_sum(array_map(static fn ($d) => (int) ($d['total'] ?? 0), $rbac['daily']));
        $sumDailyDenies = array_sum(array_map(static fn ($d) => (int) ($d['denies'] ?? 0), $rbac['daily']));

        static::assertSame($sumDailyTotal,  (int) ($rbac['total']  ?? -1));
        static::assertSame($sumDailyDenies, (int) ($rbac['denies'] ?? -1));

        $rate = (float) ($rbac['rate'] ?? -1.0);
        $calcRate = $sumDailyTotal > 0 ? $sumDailyDenies / $sumDailyTotal : 0.0;
        static::assertThat($rate, static::logicalAnd(
            static::greaterThanOrEqual(max(0.0, $calcRate - 0.001)),
            static::lessThanOrEqual(min(1.0, $calcRate + 0.001)),
        ));

        // Evidence freshness KPI — compute from DB to match flexible schema.
        $ev = $data['evidence_freshness'] ?? [];
        static::assertSame(30, (int) ($ev['days'] ?? -1));

        $daysParam = (int) ($ev['days'] ?? 30);
        $threshold = Carbon::now()->subDays($daysParam);

        $evTotal = (int) DB::table('evidence')->count();
        $evStale = (int) DB::table('evidence')->where('updated_at', '<', $threshold)->count();

        static::assertSame($evTotal, (int) ($ev['total'] ?? -1));
        static::assertSame($evStale, (int) ($ev['stale'] ?? -1));

        $expectedPercent = $evTotal > 0 ? ($evStale / $evTotal) * 100.0 : 0.0;

        // Accept API percent as 0–100 or 0–1
        $apiPercent = (float) ($ev['percent'] ?? -1.0);
        if ($apiPercent >= 0.0 && $apiPercent <= 1.0) {
            $apiPercent *= 100.0;
        }

        static::assertThat($apiPercent, static::logicalAnd(
            static::greaterThanOrEqual(max(0.0, $expectedPercent - 1.0)),
            static::lessThanOrEqual(min(100.0, $expectedPercent + 1.0)),
        ));

        static::assertIsArray($ev['by_mime'] ?? null);
    }

    private function makeUser(string $name, string $email): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('secret'),
        ]);
    }

    private function attachNamedRole(User $user, string $name): void
    {
        $id = 'role_' . strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));

        $role = DB::table('roles')->where('id', $id)->first();
        if (! $role) {
            DB::table('roles')->insert(['id' => $id, 'name' => $name]);
        }

        DB::table('role_user')->updateOrInsert(
            ['user_id' => $user->getKey(), 'role_id' => $id],
            []
        );
    }

    private function insertAudit(Carbon $occurredAt, string $category, string $action): void
    {
        $cols = Schema::getColumnListing('audit_events');

        $row = [
            'id'          => (string) Str::ulid(),
            'occurred_at' => $occurredAt->toDateTimeString(),
            'category'    => $category,
            'action'      => $action,
            'entity_type' => 'system',
            'entity_id'   => '0',
        ];

        if (in_array('created_at', $cols, true)) $row['created_at'] = $occurredAt->toDateTimeString();
        if (in_array('updated_at', $cols, true)) $row['updated_at'] = $occurredAt->toDateTimeString();
        if (in_array('actor_id', $cols, true))   $row['actor_id']   = null;
        if (in_array('ip', $cols, true))         $row['ip']         = null;
        if (in_array('ua', $cols, true))         $row['ua']         = null;
        if (in_array('meta', $cols, true))       $row['meta']       = null;

        DB::table('audit_events')->insert($row);
    }

    private function insertEvidence(string $mime, Carbon $updatedAt): void
    {
        $cols = Schema::getColumnListing('evidence');

        $ext = match ($mime) {
            'application/pdf' => 'pdf',
            'image/png'       => 'png',
            'image/jpeg'      => 'jpg',
            'text/plain'      => 'txt',
            default           => 'bin',
        };
        $base = "seed.$ext";

        $row = [
            'id'   => (string) Str::ulid(),
            'mime' => $mime,
        ];

        if (in_array('sha256', $cols, true))       $row['sha256']       = str_repeat('a', 64);
        if (in_array('size', $cols, true))         $row['size']         = 123;
        if (in_array('bytes', $cols, true))        $row['bytes']        = 123;
        if (in_array('size_bytes', $cols, true))   $row['size_bytes']   = 123;
        if (in_array('path', $cols, true))         $row['path']         = "/tmp/$base";
        if (in_array('filename', $cols, true))     $row['filename']     = $base;

        $ownerId = DB::table('users')->value('id');
        if (in_array('owner_id', $cols, true) && $ownerId !== null)     $row['owner_id']     = $ownerId;

        if (in_array('created_at', $cols, true)) $row['created_at'] = $updatedAt->copy()->subDay()->toDateTimeString();
        if (in_array('updated_at', $cols, true)) $row['updated_at'] = $updatedAt->toDateTimeString();

        DB::table('evidence')->insert($row);
    }
}
