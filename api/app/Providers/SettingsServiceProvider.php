<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

final class SettingsServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        // no bindings
    }
    
    public function boot(): void
    {
        /** @var ConfigRepository $config */
        $config = $this->app->make(ConfigRepository::class);

        // If DB not ready, bail.
        try {
            if (!Schema::hasTable('core_settings')) {
                return;
            }
        } catch (\Throwable $e) {
            return;
        }

        try {
            /** @var array<int, array{key:string,value:string,type:string}> $rows */
            $rows = DB::table('core_settings')
                ->select(['key', 'value', 'type'])
                ->get()
                ->map(static function ($r): array {
                    return [
                        'key'   => (string) ($r->key ?? ''),
                        'value' => (string) ($r->value ?? ''),
                        'type'  => (string) ($r->type ?? 'json'),
                    ];
                })
                ->all();
        } catch (\Throwable $e) {
            return;
        }

        foreach ($rows as $row) {
            $key   = $row['key'];
            $type  = strtolower($row['type']);
            $value = $row['value'];

            if ($type === 'json') {
                /** @var mixed $decoded */
                $decoded = json_decode($value, true);
                if ($decoded === null && json_last_error() !== JSON_ERROR_NONE) {
                    continue;
                }
                $config->set($key, $decoded);
                continue;
            }

            if ($type === 'bool') {
                $v = strtolower(trim($value));
                $config->set($key, in_array($v, ['1', 'true', 'on', 'yes'], true));
                continue;
            }

            if ($type === 'int') {
                $config->set($key, (int) $value);
                continue;
            }

            if ($type === 'float') {
                $config->set($key, (float) $value);
                continue;
            }

            // default to string
            $config->set($key, $value);
        }
    }
}
