<?php

declare(strict_types=1);

namespace App\Http\Controllers\Metrics;

use App\Http\Controllers\Controller;
use App\Services\Metrics\CachedMetricsService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Validator as LaravelValidator;

final class MetricsController extends Controller
{
    /**
     * Back-compat for routes that still point to MetricsController@index.
     */
    public function index(Request $request, CachedMetricsService $metrics): JsonResponse
    {
        return $this->kpis($request, $metrics);
    }

    public function kpis(Request $request, CachedMetricsService $metrics): JsonResponse
    {
        $defaultAuth = $this->cfgInt('core.metrics.rbac_denies.window_days', 7);

        // Legacy query param 'rbac_days' is still accepted.
        $authParam = $request->query('auth_days');
        if ($authParam === null) {
            $authParam = $request->query('rbac_days');
        }

        $authDays = $this->parseWindow($authParam, $defaultAuth);

        /**
         * @var array{
         *   ok: bool,
         *   errors?: array<string, array<int, string>>,
         *   window?: array{
         *     tz: string,
         *     granularity: string,
         *     from?: string,
         *     to?: string,
         *     auth_days?: int
         *   }
         * } $future
         */
        $future = $this->parseFutureParams(
            $request,
            $this->effectiveMaxDays()
        );

        if ($future['ok'] === false) {
            return $this->validationError($future['errors'] ?? []);
        }

        if (isset($future['window']['auth_days'])) {
            /** @var int $resolved */
            $resolved = $future['window']['auth_days'];
            $authDays = $resolved;
        }

        /**
         * @var array{
         *   data: array{
         *     auth_activity: array{
         *       window_days:int,
         *       from: non-empty-string,
         *       to: non-empty-string,
         *       daily:list<array{date:non-empty-string,success:int,failed:int,total:int}>,
         *       totals:array{success:int,failed:int,total:int},
         *       max_daily_total:int
         *     },
         *     evidence_mime: array{
         *       total:int,
         *       by_mime:list<array{mime:non-empty-string,count:int,percent:float}>
         *     },
         *     admin_activity: array{
         *       admins:list<array{id:int,name:string,email:string,last_login_at:string|null}>
         *     }
         *   },
         *   cache: array{ttl:int, hit:bool}
         * } $res
         */
        $res = $metrics->snapshotWithMeta($authDays);

        $data = $res['data'];
        $cache = $res['cache']; // array{ttl:int, hit:bool}

        // Compose meta.window without breaking existing shape
        $windowMeta = [
            'auth_days' => $authDays,
            'rbac_days' => $authDays, // legacy alias for consumers expecting rbac_days
        ];
        if (isset($future['window'])) {
            $win = $future['window'];
            $windowMeta['tz'] = $win['tz'];
            $windowMeta['granularity'] = $win['granularity'];
            if (isset($win['from'])) {
                $windowMeta['from'] = $win['from'];
            }
            if (isset($win['to'])) {
                $windowMeta['to'] = $win['to'];
            }
        }

        return new JsonResponse([
            'ok' => true,
            'data' => $data,
            'meta' => [
                'generated_at' => now('UTC')->toIso8601String(),
                'window' => $windowMeta,
                'cache' => ['ttl' => $cache['ttl'], 'hit' => $cache['hit']],
            ],
        ], 200);
    }

    private function cfgInt(string $key, int $fallback): int
    {
        /** @var mixed $v */
        $v = config($key);

        if (is_int($v)) {
            return $v;
        }
        if (is_string($v) && $v !== '' && ctype_digit($v)) {
            return (int) $v;
        }

        return $fallback;
    }

    /**
     * Resolve min/max clamp bounds from config.
     *
     * @return array{min:int,max:int}
     */
    private function clampBounds(): array
    {
        $min = $this->cfgInt('core.metrics.window.min_days', 1);
        if ($min < 1) {
            $min = 1;
        }
        $max = $this->cfgInt('core.metrics.window.max_days', 365);
        if ($max < $min) {
            $max = $min;
        }

        return ['min' => $min, 'max' => $max];
    }

    private function effectiveMaxDays(): int
    {
        return $this->clampBounds()['max'];
    }

    /**
     * @param  mixed  $raw  query param (int|string|array<int|string>|null)
     */
    private function parseWindow(mixed $raw, int $fallback): int
    {
        $bounds = $this->clampBounds();
        $min = $bounds['min'];
        $max = $bounds['max'];

        /** @var mixed $value */
        $value = is_array($raw) ? Arr::first($raw) : $raw;

        if (is_int($value)) {
            $n = $value;
        } elseif (is_string($value) && ctype_digit(ltrim($value, '+-'))) {
            $n = (int) $value;
        } else {
            $n = $fallback;
        }

        if ($n < $min) {
            return $min;
        }
        if ($n > $max) {
            return $max;
        }

        return $n;
    }

    /**
     * Parse optional future params: from, to, tz, granularity.
     * tz: IANA timezone, default UTC.
     * granularity: only "day" supported.
     * from/to: if both present and valid compute inclusive day window for RBAC; clamp 1..$maxDays.
     *
     * If only one of from/to supplied, they are ignored without error.
     *
     * @return array{
     *   ok: bool,
     *   errors?: array<string, array<int, string>>,
     *   window?: array{
     *     tz: string,
     *     granularity: string,
     *     from?: string,
     *     to?: string,
     *     auth_days?: int,
     *     rbac_days?: int
     *   }
     * }
     */
    private function parseFutureParams(Request $request, int $maxDays): array
    {
        $fromStr = $this->qsString($request, 'from');
        $toStr = $this->qsString($request, 'to');
        $tzStr = $this->qsString($request, 'tz');
        $granStr = $this->qsString($request, 'granularity');

        $input = [
            'from' => $fromStr,
            'to' => $toStr,
            'tz' => $tzStr,
            'granularity' => $granStr,
        ];

        /** @var LaravelValidator $v */
        $v = Validator::make($input, [
            'from' => ['nullable', 'string'],
            'to' => ['nullable', 'string'],
            'tz' => ['nullable', 'timezone'],
            'granularity' => ['nullable', 'in:day'],
        ]);

        if ($v->fails()) {
            /** @var array<string, array<int, string>> $errs */
            $errs = $v->errors()->getMessages();

            return [
                'ok' => false,
                'errors' => $errs,
            ];
        }

        $tz = ($tzStr !== null && $tzStr !== '') ? $tzStr : 'UTC';
        $gran = ($granStr !== null && $granStr !== '') ? $granStr : 'day';

        $out = [
            'tz' => $tz,
            'granularity' => $gran,
        ];

        $hasFrom = is_string($fromStr) && $fromStr !== '';
        $hasTo = is_string($toStr) && $toStr !== '';

        if (! $hasFrom && ! $hasTo) {
            return ['ok' => true, 'window' => $out];
        }

        if ($hasFrom xor $hasTo) {
            // Incomplete range supplied -> ignore silently and fallback
            return ['ok' => true, 'window' => $out];
        }

        // Format guard before parsing to avoid Carbon accepting garbage.
        if (! $this->isAcceptableDateTime($fromStr)) {
            return [
                'ok' => false,
                'errors' => ['from' => ['INVALID_DATETIME']],
            ];
        }
        if (! $this->isAcceptableDateTime($toStr)) {
            return [
                'ok' => false,
                'errors' => ['to' => ['INVALID_DATETIME']],
            ];
        }

        try {
            $from = CarbonImmutable::parse((string) $fromStr, $tz)->startOfDay()->utc();
        } catch (\Throwable) {
            return [
                'ok' => false,
                'errors' => ['from' => ['INVALID_DATETIME']],
            ];
        }

        try {
            $to = CarbonImmutable::parse((string) $toStr, $tz)->endOfDay()->utc();
        } catch (\Throwable) {
            return [
                'ok' => false,
                'errors' => ['to' => ['INVALID_DATETIME']],
            ];
        }

        if ($from->gt($to)) {
            return [
                'ok' => false,
                'errors' => ['from' => ['AFTER_TO']],
            ];
        }

        // Inclusive number of days
        $days = (int) $from->diffInDays($to) + 1;
        if ($days < 1) {
            $days = 1;
        } elseif ($days > $maxDays) {
            $days = $maxDays;
        }

        $out['from'] = $from->toIso8601String();
        $out['to'] = $to->toIso8601String();
        $out['auth_days'] = $days;
        $out['rbac_days'] = $days;

        return ['ok' => true, 'window' => $out];
    }

    /**
     * Standardized validation error envelope.
     * { ok:false, code:"VALIDATION_FAILED", errors:{...} }
     *
     * @param  array<string, array<int, string>>  $errors
     */
    private function validationError(array $errors): JsonResponse
    {
        return new JsonResponse([
            'ok' => false,
            'code' => 'VALIDATION_FAILED',
            'errors' => $errors,
        ], 422);
    }

    /**
     * Safely read a query string parameter as a nullable string.
     */
    private function qsString(Request $request, string $key): ?string
    {
        $raw = $request->query($key);

        if (is_string($raw)) {
            return $raw;
        }
        if (is_array($raw)) {
            /** @var array<int, string> $strings */
            $strings = array_values(array_filter($raw, 'is_string'));
            if ($strings !== []) {
                return $strings[0];
            }

            return null;
        }

        return null;
    }

    /**
     * Accept YYYY-MM-DD or ISO-8601 date-time with offset (e.g., 2025-09-01T12:00:00Z or +05:30).
     */
    private function isAcceptableDateTime(?string $s): bool
    {
        if ($s === null || $s === '') {
            return false;
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) === 1) {
            return true;
        }

        return preg_match(
            '/^\d{4}-\d{2}-\d{2}[Tt ][0-2]\d:[0-5]\d(?::[0-5]\d(?:\.\d{1,6})?)?(Z|[+\-][0-2]\d:[0-5]\d)$/',
            $s
        ) === 1;
    }
}
