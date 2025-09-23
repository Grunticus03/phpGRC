<?php
declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('core_settings')) {
            return;
        }

        $now = now();

        $rows = [
            // RBAC
            ['key' => 'core.rbac.enabled',                 'value' => '1',    'type' => 'bool', 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'core.rbac.require_auth',            'value' => '0',    'type' => 'bool', 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'core.rbac.roles',                   'value' => json_encode(['Admin','Auditor','Risk Manager','User'], JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],

            // Audit
            ['key' => 'core.audit.enabled',                'value' => '1',    'type' => 'bool', 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'core.audit.retention_days',         'value' => '365',  'type' => 'int',  'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],

            // Evidence
            ['key' => 'core.evidence.enabled',             'value' => '1',    'type' => 'bool', 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'core.evidence.max_mb',              'value' => '25',   'type' => 'int',  'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'core.evidence.allowed_mime',        'value' => json_encode(['application/pdf','image/png','image/jpeg','image/webp','text/plain'], JSON_UNESCAPED_UNICODE), 'type' => 'json', 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],

            // Avatars
            ['key' => 'core.avatars.enabled',              'value' => '1',    'type' => 'bool', 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'core.avatars.size_px',              'value' => '128',  'type' => 'int',  'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'core.avatars.format',               'value' => 'webp', 'type' => 'string', 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],

            // Metrics (DB-backed)
            ['key' => 'core.metrics.cache_ttl_seconds',          'value' => '0',   'type' => 'int',  'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'core.metrics.evidence_freshness.days',    'value' => '30',  'type' => 'int',  'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
            ['key' => 'core.metrics.rbac_denies.window_days',    'value' => '7',   'type' => 'int',  'updated_by' => null, 'created_at' => $now, 'updated_at' => $now],
        ];

        DB::table('core_settings')->upsert(
            $rows,
            ['key'],
            ['value','type','updated_by','updated_at'] // keep created_at on existing rows
        );
    }

    public function down(): void
    {
        if (!Schema::hasTable('core_settings')) {
            return;
        }

        DB::table('core_settings')->whereIn('key', [
            'core.rbac.enabled',
            'core.rbac.require_auth',
            'core.rbac.roles',
            'core.audit.enabled',
            'core.audit.retention_days',
            'core.evidence.enabled',
            'core.evidence.max_mb',
            'core.evidence.allowed_mime',
            'core.avatars.enabled',
            'core.avatars.size_px',
            'core.avatars.format',
            'core.metrics.cache_ttl_seconds',
            'core.metrics.evidence_freshness.days',
            'core.metrics.rbac_denies.window_days',
        ])->delete();
    }
};
