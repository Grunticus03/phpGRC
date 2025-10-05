<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rbac;

use App\Http\Requests\Rbac\RoleCreateRequest;
use App\Models\Role;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Database\Seeders\RolesSeeder;

final class RolesController extends Controller
{
    private bool $seedDefaultsEnsured = false;

    private function persistenceEnabled(): bool
    {
        /** @var mixed $flagRaw */
        $flagRaw = config('core.rbac.persistence');
        $flag = filter_var($flagRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;

        /** @var mixed $modeRaw */
        $modeRaw = config('core.rbac.mode');
        $mode = is_string($modeRaw) ? $modeRaw : 'stub';

        return $flag || $mode === 'persist' || $mode === 'db';
    }

    private function ensureSeedDefaults(): void
    {
        if ($this->seedDefaultsEnsured) {
            return;
        }

        if (!Schema::hasTable('roles')) {
            $this->seedDefaultsEnsured = true;
            return;
        }

        $expectedIds = [
            'role_admin',
            'role_auditor',
            'role_risk_mgr',
            'role_user',
        ];

        /** @var list<string> $present */
        $present = Role::query()
            ->whereIn('id', $expectedIds)
            ->pluck('id')
            ->filter(static fn ($v): bool => is_string($v) && $v !== '')
            ->values()
            ->all();

        $missing = array_diff($expectedIds, $present);

        if ($missing !== []) {
            try {
                app(RolesSeeder::class)->run();
            } catch (\Throwable) {
                return;
            }

            /** @var list<string> $present */
            $present = Role::query()
                ->whereIn('id', $expectedIds)
                ->pluck('id')
                ->filter(static fn ($v): bool => is_string($v) && $v !== '')
                ->values()
                ->all();

            $missing = array_diff($expectedIds, $present);
            if ($missing !== []) {
                return;
            }
        }

        $this->seedDefaultsEnsured = true;
    }

    public function index(): JsonResponse
    {
        if (!$this->persistenceEnabled()) {
            /** @var array<array-key, mixed> $cfgArr */
            $cfgArr = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);

            /** @var list<string> $cfg */
            $cfg = array_values(array_filter(
                array_map(
                    static fn (mixed $v): ?string => is_string($v) ? $v : null,
                    $cfgArr
                ),
                static fn (?string $v): bool => $v !== null
            ));

            if ($cfg === []) {
                $cfg = ['Admin', 'Auditor', 'Risk Manager', 'User'];
            }

            return response()->json(['ok' => true, 'roles' => $cfg], 200);
        }

        /** @var list<string> $names */
        $names = [];
        if (Schema::hasTable('roles')) {
            /** @var list<string> $namesFromDb */
            $namesFromDb = Role::query()
                ->orderBy('name')
                ->pluck('name')
                ->filter(static fn ($v): bool => is_string($v))
                ->values()
                ->all();
            $names = $namesFromDb;
        }
        if ($names === []) {
            /** @var array<array-key, mixed> $fallbackArr */
            $fallbackArr = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);

            /** @var list<string> $fallback */
            $fallback = array_values(array_filter(
                array_map(
                    static fn (mixed $v): ?string => is_string($v) ? $v : null,
                    $fallbackArr
                ),
                static fn (?string $v): bool => $v !== null
            ));

            if ($fallback === []) {
                $fallback = ['Admin', 'Auditor', 'Risk Manager', 'User'];
            }
            $names = $fallback;
        }

        return response()->json(['ok' => true, 'roles' => $names], 200);
    }

    public function store(RoleCreateRequest $request): JsonResponse
    {
        if (!$this->persistenceEnabled() || !Schema::hasTable('roles')) {
            return response()->json([
                'ok'       => true,
                'note'     => 'stub-only',
                'accepted' => ['name' => $request->string('name')->toString()],
            ], 202);
        }

        $this->ensureSeedDefaults();

        $raw = $request->string('name')->toString();
        $trimmed = trim($raw);
        /** @var string $collapsed */
        $collapsed = (string) preg_replace('/\s+/u', ' ', $trimmed);
        $nameLower = mb_strtolower($collapsed, 'UTF-8');

        /** @var list<string> $existingNames */
        $existingNames = array_values(array_map(
            static fn ($v): string => (string) $v,
            Role::query()
                ->pluck('name')
                ->filter(static fn ($v): bool => is_string($v))
                ->all()
        ));

        foreach ($existingNames as $ex) {
            if (mb_strtolower($ex, 'UTF-8') === $nameLower) {
                return response()->json([
                    'ok'      => false,
                    'code'    => 'VALIDATION_FAILED',
                    'message' => 'The given data was invalid.',
                    'errors'  => ['name' => ['Role already exists after normalization.']],
                ], 422);
            }
        }

        $base = 'role_' . Str::slug($raw, '_');

        if (Role::query()->whereKey($base)->exists()) {
            /** @var list<string> $siblings */
            $siblings = Role::query()
                ->where('id', 'like', $base . '\_%')
                ->pluck('id')
                ->filter(static fn ($v): bool => is_string($v))
                ->values()
                ->all();

            $max = 0;
            foreach ($siblings as $sibId) {
                if (preg_match('/^' . preg_quote($base, '/') . '_(\d+)$/', $sibId, $m) === 1) {
                    $n = (int) $m[1];
                    if ($n > $max) {
                        $max = $n;
                    }
                }
            }
            $id = $base . '_' . ($max + 1);
        } else {
            $id = $base;
        }

        // store normalized lowercase
        $role = Role::query()->create(['id' => $id, 'name' => $nameLower]);

        try {
            /** @var AuditLogger $logger */
            $logger  = app(AuditLogger::class);
            $actorId = Auth::id();

            /** @var non-empty-string $entityId */
            $entityId = $this->nes($role->id);

            $logger->log([
                'actor_id'    => is_int($actorId) ? $actorId : null,
                'action'      => 'rbac.role.created',
                'category'    => 'RBAC',
                'entity_type' => 'role',
                'entity_id'   => $entityId,
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => [
                    'name'            => $collapsed,
                    'name_normalized' => $nameLower,
                ],
            ]);
        } catch (\Throwable) {
            // ignore audit errors
        }

        return response()->json([
            'ok'   => true,
            'role' => ['id' => $role->id, 'name' => $role->name],
        ], 201);
    }

    /**
     * @return non-empty-string
     */
    private function nes(string $s): string
    {
        if ($s === '') {
            throw new \LogicException('Expected non-empty string');
        }
        return $s;
    }
}

