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
            if (! Schema::hasTable('core_settings')) {
                // Ensure deprecated metrics throttle stays off even without DB
                $config->set('core.metrics.throttle.enabled', false);

                return;
            }
        } catch (\Throwable $e) {
            $config->set('core.metrics.throttle.enabled', false);

            return;
        }

        try {
            /** @var \Illuminate\Support\Collection<int, object> $rowsRaw */
            $rowsRaw = DB::table('core_settings')
                ->select(['key', 'value', 'type'])
                ->get();
        } catch (\Throwable $e) {
            // Still enforce deprecated toggle off
            $config->set('core.metrics.throttle.enabled', false);

            return;
        }

        foreach ($rowsRaw as $rowRaw) {
            /** @var mixed $keyCandidate */
            $keyCandidate = $rowRaw->key ?? null;
            if (! is_string($keyCandidate) || $keyCandidate === '') {
                continue;
            }
            $key = $keyCandidate;

            /** @var mixed $valueCandidate */
            $valueCandidate = $rowRaw->value ?? '';
            if (! is_string($valueCandidate)) {
                continue;
            }
            $value = $valueCandidate;

            /** @var mixed $typeCandidate */
            $typeCandidate = $rowRaw->type ?? 'json';
            $type = is_string($typeCandidate) && $typeCandidate !== '' ? strtolower($typeCandidate) : 'json';

            // ENV-first precedence: do NOT allow DB to overwrite global API throttle knobs.
            // These map from CORE_API_THROTTLE_* in config and must win.
            if (str_starts_with($key, 'core.api.throttle.')) {
                continue;
            }

            // Hard-disable deprecated metrics throttle regardless of DB contents.
            if ($key === 'core.metrics.throttle.enabled') {
                // Skip any DB attempt to enable it
                continue;
            }

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

        // Enforce deprecated toggle off after overlay for certainty.
        $config->set('core.metrics.throttle.enabled', false);
    }
}
