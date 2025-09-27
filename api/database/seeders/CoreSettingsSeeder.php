<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class CoreSettingsSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('core_settings')) {
            return;
        }

        $this->put('core.metrics.cache_ttl_seconds', 0);
        $this->put('core.metrics.evidence_freshness.days', 30);
        $this->put('core.metrics.rbac_denies.window_days', 7);

        // Metrics throttle defaults
        $this->put('core.metrics.throttle.enabled', true);
        $this->put('core.metrics.throttle.per_minute', 30);
        $this->put('core.metrics.throttle.window_seconds', 60);

        // Auth bruteforce defaults
        $this->put('core.auth.bruteforce.enabled', true);
        $this->put('core.auth.bruteforce.strategy', 'session');
        $this->put('core.auth.bruteforce.window_seconds', 900);
        $this->put('core.auth.bruteforce.max_attempts', 5);
        $this->put('core.auth.session_cookie.name', 'phpgrc_auth_attempt');
    }

    private function put(string $key, mixed $value): void
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return;
        }

        $type = match (true) {
            is_bool($value)  => 'bool',
            is_int($value)   => 'int',
            is_float($value) => 'float',
            is_string($value)=> 'string',
            is_array($value) => 'array',
            default          => 'mixed',
        };

        DB::table('core_settings')->updateOrInsert(
            ['key' => $key],
            ['value' => $json, 'type' => $type, 'updated_at' => now(), 'created_at' => now()]
        );
    }
}
