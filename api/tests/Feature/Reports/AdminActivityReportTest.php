<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AdminActivityReportTest extends TestCase
{
    use RefreshDatabase;

    #[\Override]
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.rbac.enabled' => true,
            'core.rbac.require_auth' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.policies' => array_merge(config('core.rbac.policies', []), [
                'core.reports.view' => ['role_admin'],
            ]),
        ]);
    }

    #[\Override]
    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_admin_can_fetch_json_report(): void
    {
        $admin = $this->makeUser('Admin Report', 'admin-report@example.test');
        $this->attachNamedRole($admin, 'Admin');

        $now = CarbonImmutable::parse('2025-10-01T00:00:00Z');
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);

        $this->insertLogin($admin, $now->subDays(1));  // within 7 & 30
        $this->insertLogin($admin, $now->subDays(6));  // within 7 & 30
        $this->insertLogin($admin, $now->subDays(21)); // within 30
        $this->insertLogin($admin, $now->subDays(45)); // total only

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/reports/admin-activity');
        $resp->assertStatus(200);

        $json = $resp->json();
        self::assertSame(true, $json['ok'] ?? null);

        $rows = $json['data']['rows'] ?? [];
        $this->assertCount(1, $rows);

        $row = $rows[0];

        $this->assertSame($admin->getKey(), (int) ($row['id'] ?? 0));
        $this->assertSame('admin-report@example.test', $row['email'] ?? null);
        $this->assertSame(4, (int) ($row['logins_total'] ?? -1));
        $this->assertSame(3, (int) ($row['logins_30_days'] ?? -1));
        $this->assertSame(2, (int) ($row['logins_7_days'] ?? -1));

        $this->assertSame(
            $this->formatIso($now->subDays(1)),
            $row['last_login_at'] ?? null
        );

        $totals = $json['data']['totals'] ?? [];
        $this->assertSame(1, (int) ($totals['admins'] ?? -1));
        $this->assertSame(4, (int) ($totals['logins_total'] ?? -1));
        $this->assertSame(3, (int) ($totals['logins_30_days'] ?? -1));
        $this->assertSame(2, (int) ($totals['logins_7_days'] ?? -1));
    }

    public function test_admin_can_download_csv_report(): void
    {
        $admin = $this->makeUser('CSV Admin', 'csv-admin@example.test');
        $this->attachNamedRole($admin, 'Admin');

        $now = CarbonImmutable::parse('2025-10-05T00:00:00Z');
        CarbonImmutable::setTestNow($now);
        Carbon::setTestNow($now);
        $this->insertLogin($admin, $now->subHours(2));

        $resp = $this->actingAs($admin, 'sanctum')->get('/reports/admin-activity?format=csv');
        $resp->assertStatus(200);
        $resp->assertHeader('Content-Type', 'text/csv; charset=UTF-8');

        $body = $resp->getContent();
        $this->assertIsString($body);
        $this->assertStringContainsString("id,name,email,last_login_at,logins_total,logins_30_days,logins_7_days\n", $body);
        $this->assertStringContainsString('csv-admin@example.test', $body);

        $disposition = $resp->headers->get('Content-Disposition');
        $this->assertIsString($disposition);
        $this->assertStringContainsString('attachment; filename="admin-activity-report-', $disposition);
    }

    public function test_non_admin_forbidden(): void
    {
        $user = $this->makeUser('Regular User', 'user@example.test');
        $this->attachNamedRole($user, 'User');

        $resp = $this->actingAs($user, 'sanctum')->getJson('/reports/admin-activity');
        $resp->assertStatus(403);
    }

    public function test_invalid_format_rejected(): void
    {
        $admin = $this->makeUser('Format Admin', 'format-admin@example.test');
        $this->attachNamedRole($admin, 'Admin');

        $resp = $this->actingAs($admin, 'sanctum')->getJson('/reports/admin-activity?format=xml');
        $resp->assertStatus(422);

        $json = $resp->json();
        $this->assertSame('VALIDATION_FAILED', $json['code'] ?? null);
        $this->assertArrayHasKey('format', $json['errors'] ?? []);
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
        if ($role === null) {
            DB::table('roles')->insert(['id' => $id, 'name' => $name]);
        }

        DB::table('role_user')->updateOrInsert(
            ['user_id' => $user->getKey(), 'role_id' => $id],
            []
        );
    }

    private function insertLogin(User $user, CarbonImmutable $occurredAt): void
    {
        $cols = Schema::getColumnListing('audit_events');

        $row = [
            'id' => (string) Str::ulid(),
            'occurred_at' => $occurredAt->toDateTimeString(),
            'category' => 'AUTH',
            'action' => 'auth.login',
            'entity_type' => 'core.auth',
            'entity_id' => 'login',
            'actor_id' => $user->getKey(),
        ];

        if (in_array('created_at', $cols, true)) {
            $row['created_at'] = $occurredAt->toDateTimeString();
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

    private function formatIso(CarbonImmutable $ts): string
    {
        return $ts->setTimezone('UTC')->toIso8601String();
    }
}
