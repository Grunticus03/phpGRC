<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Model as EloquentModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuditExportCursorSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_export_streams_with_cursor_and_returns_csv(): void
    {
        config([
            'core.rbac.enabled'         => true,
            'core.rbac.require_auth'    => true,
            'core.rbac.mode'            => 'persist',
            'core.audit.enabled'        => true,
            'core.audit.csv_use_cursor' => true,
        ]);

        // Seed many audit rows to exercise streaming path
        $count = 500;
        $now = Carbon::now('UTC')->startOfMinute();
        $rows = [];
        $cols = Schema::getColumnListing('audit_events');

        for ($i = 0; $i < $count; $i++) {
            $when = $now->copy()->subSeconds($i);
            $row = [
                'id'          => Str::ulid()->toBase32(),
                'occurred_at' => $when->toDateTimeString(),
                'category'    => $i % 3 === 0 ? 'RBAC' : ($i % 3 === 1 ? 'AUTH' : 'SYSTEM'),
                'action'      => $i % 3 === 0 ? 'rbac.allow' : 'auth.login.success',
                'entity_type' => 'test',
                'entity_id'   => 'seed',
            ];
            if (in_array('created_at', $cols, true)) $row['created_at'] = $when->toDateTimeString();
            if (in_array('actor_id', $cols, true))   $row['actor_id']   = null;
            if (in_array('ip', $cols, true))         $row['ip']         = null;
            if (in_array('ua', $cols, true))         $row['ua']         = null;
            if (in_array('meta', $cols, true))       $row['meta']       = null;
            $rows[] = $row;
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('audit_events')->insert($chunk);
        }

        // Auditor can view and export
        $auditor = $this->makeUser('Auditor CSV', 'aud.csv@example.test');
        $this->attachNamedRole($auditor, 'Auditor');

        $resp = $this->actingAs($auditor, 'sanctum')->get('/audit/export.csv');

        $resp->assertStatus(200);

        // Content-Type can be "text/csv" or "text/csv; charset=UTF-8"
        $ctype = (string) $resp->headers->get('Content-Type', '');
        $this->assertNotSame('', $ctype);
        $this->assertStringStartsWith('text/csv', $ctype);

        $body = (string) $resp->getContent();
        $this->assertNotSame('', $body);
        $lines = preg_split("/\r\n|\n|\r/", $body) ?: [];
        $this->assertGreaterThan(1, count($lines));
        $header = $lines[0] ?? '';
        $this->assertStringContainsString('id', $header);
        $this->assertStringContainsString('occurred_at', $header);
        $this->assertStringContainsString('category', $header);
        $this->assertStringContainsString('action', $header);
    }

    /** Helpers */
    private function makeUser(string $name, string $email): User
    {
        /** @var User $user */
        $user = EloquentModel::unguarded(fn () => User::query()->create([
            'name' => $name,
            'email' => $email,
            'password' => bcrypt('secret'),
        ]));
        return $user;
    }

    private function attachNamedRole(User $user, string $name): void
    {
        $id = 'role_' . strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));
        /** @var Role $role */
        $role = Role::query()->firstOrCreate(['id' => $id], ['name' => $name]);
        $user->roles()->syncWithoutDetaching([$role->getKey()]);
    }
}
