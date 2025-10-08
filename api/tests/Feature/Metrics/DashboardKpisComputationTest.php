<?php

declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Models\User;
use Carbon\CarbonImmutable;
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

        $otherAdmin = $this->makeUser('Admin Two', 'admin2@example.test');
        $this->attachNamedRole($otherAdmin, 'Admin');

        $now = Carbon::now('UTC')->startOfDay();
        $successPerDay = 5;
        $failPerDay = 2;

        for ($d = 0; $d < 7; $d++) {
            $day = (clone $now)->subDays($d);
            for ($i = 0; $i < $successPerDay; $i++) {
                $ts = (clone $day)->addMinutes($i);
                $this->insertAudit($ts, 'AUTH', 'auth.login', $admin->getKey());
            }
            for ($j = 0; $j < $failPerDay; $j++) {
                $ts = (clone $day)->addMinutes(200 + $j);
                $this->insertAudit($ts, 'AUTH', 'auth.login.failed', null);
            }
        }

        $mimeCounts = [
            'application/pdf' => 3,
            'image/png' => 2,
            'text/plain' => 1,
        ];

        foreach ($mimeCounts as $mime => $count) {
            for ($i = 0; $i < $count; $i++) {
                $this->insertEvidence($mime, (clone $now)->subDays($i + 1));
            }
        }

        $latestLoginRaw = DB::table('audit_events')
            ->where('action', '=', 'auth.login')
            ->where('actor_id', '=', $admin->getKey())
            ->max('occurred_at');

        $expectedLastLoginIso = $latestLoginRaw
            ? CarbonImmutable::parse((string) $latestLoginRaw)->setTimezone('UTC')->toIso8601String()
            : null;

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/dashboard/kpis');
        $resp->assertStatus(200);

        $json = $resp->json();
        $data = is_array($json) && array_key_exists('data', $json) ? $json['data'] : $json;

        $auth = $data['auth_activity'] ?? [];
        self::assertSame(7, (int) ($auth['window_days'] ?? -1));
        self::assertIsArray($auth['daily'] ?? null);
        self::assertCount(7, $auth['daily'] ?? []);

        $sumSuccess = array_sum(array_map(static fn ($d) => (int) ($d['success'] ?? 0), $auth['daily']));
        $sumFailed = array_sum(array_map(static fn ($d) => (int) ($d['failed'] ?? 0), $auth['daily']));
        $sumTotal = array_sum(array_map(static fn ($d) => (int) ($d['total'] ?? 0), $auth['daily']));

        self::assertSame($successPerDay * 7, $sumSuccess);
        self::assertSame($failPerDay * 7, $sumFailed);
        self::assertSame($sumSuccess + $sumFailed, $sumTotal);

        $totals = $auth['totals'] ?? [];
        self::assertSame($sumSuccess, (int) ($totals['success'] ?? -1));
        self::assertSame($sumFailed, (int) ($totals['failed'] ?? -1));
        self::assertSame($sumTotal, (int) ($totals['total'] ?? -1));
        self::assertSame($successPerDay + $failPerDay, (int) ($auth['max_daily_total'] ?? -1));

        $evidence = $data['evidence_mime'] ?? [];
        self::assertSame(array_sum($mimeCounts), (int) ($evidence['total'] ?? -1));

        $byMime = collect($evidence['by_mime'] ?? []);
        foreach ($mimeCounts as $mime => $expectedCount) {
            $row = $byMime->firstWhere('mime', $mime);
            $this->assertNotNull($row, "Missing MIME row for {$mime}");
            $this->assertSame($expectedCount, (int) ($row['count'] ?? -1));
        }

        $admins = collect($data['admin_activity']['admins'] ?? []);
        $primary = $admins->firstWhere('email', $admin->email);
        $this->assertNotNull($primary);
        $this->assertSame($admin->getKey(), (int) ($primary['id'] ?? 0));
        $this->assertSame($expectedLastLoginIso, $primary['last_login_at'] ?? null);

        $secondary = $admins->firstWhere('email', $otherAdmin->email);
        $this->assertNotNull($secondary);
        $this->assertNull($secondary['last_login_at'] ?? null);
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
        $id = 'role_'.strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));

        $role = DB::table('roles')->where('id', $id)->first();
        if (! $role) {
            DB::table('roles')->insert(['id' => $id, 'name' => $name]);
        }

        DB::table('role_user')->updateOrInsert(
            ['user_id' => $user->getKey(), 'role_id' => $id],
            []
        );
    }

    private function insertAudit(Carbon $occurredAt, string $category, string $action, ?int $actorId = null): void
    {
        $cols = Schema::getColumnListing('audit_events');

        $row = [
            'id' => (string) Str::ulid(),
            'occurred_at' => $occurredAt->toDateTimeString(),
            'category' => $category,
            'action' => $action,
            'entity_type' => 'system',
            'entity_id' => '0',
        ];

        if (in_array('created_at', $cols, true)) {
            $row['created_at'] = $occurredAt->toDateTimeString();
        }
        if (in_array('updated_at', $cols, true)) {
            $row['updated_at'] = $occurredAt->toDateTimeString();
        }
        if (in_array('actor_id', $cols, true)) {
            $row['actor_id'] = $actorId;
        }
        if (in_array('ip', $cols, true)) {
            $row['ip'] = null;
        }
        if (in_array('ua', $cols, true)) {
            $row['ua'] = null;
        }
        if (in_array('meta', $cols, true)) {
            $row['meta'] = null;
        }

        DB::table('audit_events')->insert($row);
    }

    private function insertEvidence(string $mime, Carbon $updatedAt): void
    {
        $cols = Schema::getColumnListing('evidence');

        $ext = match ($mime) {
            'application/pdf' => 'pdf',
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'text/plain' => 'txt',
            default => 'bin',
        };
        $base = "seed.$ext";

        $row = [
            'id' => (string) Str::ulid(),
            'mime' => $mime,
        ];

        if (in_array('sha256', $cols, true)) {
            $row['sha256'] = str_repeat('a', 64);
        }
        if (in_array('size', $cols, true)) {
            $row['size'] = 123;
        }
        if (in_array('bytes', $cols, true)) {
            $row['bytes'] = 123;
        }
        if (in_array('size_bytes', $cols, true)) {
            $row['size_bytes'] = 123;
        }
        if (in_array('path', $cols, true)) {
            $row['path'] = "/tmp/$base";
        }
        if (in_array('filename', $cols, true)) {
            $row['filename'] = $base;
        }

        $ownerId = DB::table('users')->value('id');
        if (in_array('owner_id', $cols, true) && $ownerId !== null) {
            $row['owner_id'] = $ownerId;
        }

        if (in_array('created_at', $cols, true)) {
            $row['created_at'] = $updatedAt->copy()->subDay()->toDateTimeString();
        }
        if (in_array('updated_at', $cols, true)) {
            $row['updated_at'] = $updatedAt->toDateTimeString();
        }

        DB::table('evidence')->insert($row);
    }
}
