<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rbac;

use App\Models\User;
use App\Services\Settings\SettingsService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;

final class UserSearchController extends Controller
{
    public function search(Request $request, ?SettingsService $settings = null): JsonResponse
    {
        return $this->index($request, $settings);
    }

    public function index(Request $request, ?SettingsService $settings = null): JsonResponse
    {
        // resolve service if unit tests call without DI
        if ($settings === null) {
            /** @var SettingsService $settings */
            $settings = app(SettingsService::class);
        }

        /** @var array<array-key,mixed>|string|null $qRaw */
        $qRaw = $request->query('q');
        $q = '';
        if (\is_string($qRaw)) {
            $q = \trim($qRaw);
        } elseif (\is_array($qRaw)) {
            /** @var list<string> $stringVals */
            $stringVals = \array_values(\array_filter(
                $qRaw,
                static fn ($v): bool => \is_string($v)
            ));
            $first = $stringVals[0] ?? '';
            $q = \trim($first);
        }

        if ($q === '') {
            return response()->json([
                'ok' => false,
                'code' => 'VALIDATION_FAILED',
                'message' => 'Query is required.',
                'errors' => ['q' => ['The q field is required.']],
            ], 422);
        }

        // Default per_page from settings, clamped [1,500], fallback 50.
        /** @var mixed $cfgDefaultRaw */
        $cfgDefaultRaw = data_get(
            $settings->effectiveConfig(),
            'core.rbac.user_search.default_per_page',
            50
        );
        $cfgDefault = 50;
        if (\is_int($cfgDefaultRaw)) {
            $cfgDefault = $cfgDefaultRaw;
        } elseif (\is_string($cfgDefaultRaw) && $cfgDefaultRaw !== '' && \preg_match('/^\d+$/', $cfgDefaultRaw) === 1) {
            $cfgDefault = (int) $cfgDefaultRaw;
        }
        $cfgDefault = max(1, min(500, $cfgDefault));

        // Pagination: page ≥1, per_page ∈ [1,500], default comes from settings.
        $page = self::parseIntQuery($request, 'page', 1, 1, PHP_INT_MAX);
        $perPage = self::parseIntQuery($request, 'per_page', $cfgDefault, 1, 500);

        if (! Schema::hasTable('users')) {
            return response()->json([
                'ok' => true,
                'data' => [],
                'meta' => [
                    'page' => $page,
                    'per_page' => $perPage,
                    'total' => 0,
                    'total_pages' => 0,
                ],
            ], 200);
        }

        /** @var Builder<User> $base */
        $base = User::query()->select(['id', 'name', 'email']);

        $filters = $this->extractFilters($q);

        foreach ($filters['terms'] as $term) {
            $like = '%'.$this->escapeLike($term).'%';
            $base->where(static function (Builder $w) use ($like): void {
                $w->where('name', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        foreach ($filters['name'] as $namePart) {
            $like = '%'.$this->escapeLike($namePart).'%';
            $base->where('name', 'like', $like);
        }

        foreach ($filters['email'] as $emailPart) {
            $like = '%'.$this->escapeLike($emailPart).'%';
            $base->where('email', 'like', $like);
        }

        if ($filters['id'] !== []) {
            $base->whereIn('id', $filters['id']);
        }

        foreach ($filters['role'] as $rolePart) {
            $like = '%'.$this->escapeLike($rolePart).'%';
            $lower = mb_strtolower($rolePart, 'UTF-8');
            $base->whereHas('roles', static function (Builder $q) use ($like, $lower): void {
                $q->where(static function (Builder $inner) use ($like, $lower): void {
                    $inner->where('roles.name', 'like', $like)
                        ->orWhere('roles.id', 'like', $like)
                        ->orWhereRaw('LOWER(roles.name) LIKE ?', ['%'.$lower.'%'])
                        ->orWhereRaw('LOWER(roles.id) LIKE ?', ['%'.$lower.'%']);
                });
            });
        }

        $total = (clone $base)->count();
        $totalPages = $total === 0 ? 0 : (int) \ceil($total / $perPage);
        $offset = ($page - 1) * $perPage;

        /** @var \Illuminate\Database\Eloquent\Collection<int,User> $rows */
        $rows = $base
            ->orderBy('id', 'asc') // numeric, stable paging
            ->offset($offset)
            ->limit($perPage)
            ->get();

        /** @var list<array{id:int,name:string,email:string}> $data */
        $data = [];
        foreach ($rows as $u) {
            $data[] = [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
            ];
        }

        return response()->json([
            'ok' => true,
            'data' => $data,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'total_pages' => $totalPages,
            ],
        ], 200);
    }

    private function escapeLike(string $s): string
    {
        return \str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }

    /**
     * @return array{terms:list<string>,name:list<string>,email:list<string>,id:list<int>,role:list<string>}
     */
    private function extractFilters(string $query): array
    {
        $tokens = $this->tokenize($query);

        /** @var list<string> $terms */
        $terms = [];
        /** @var list<string> $names */
        $names = [];
        /** @var list<string> $emails */
        $emails = [];
        /** @var list<int> $ids */
        $ids = [];
        /** @var list<string> $roles */
        $roles = [];

        foreach ($tokens as $token) {
            $colonPos = strpos($token, ':');
            if ($colonPos === false) {
                $terms[] = $token;

                continue;
            }

            $field = strtolower(substr($token, 0, $colonPos));
            $value = substr($token, $colonPos + 1);
            $value = $this->stripQuotes(trim($value));
            if ($value === '') {
                continue;
            }

            switch ($field) {
                case 'name':
                    $names[] = $value;
                    break;
                case 'email':
                    $emails[] = $value;
                    break;
                case 'id':
                    if (preg_match('/^\d+$/', $value) === 1) {
                        $ids[] = (int) $value;
                    }
                    break;
                case 'role':
                case 'roles':
                    $roles[] = $value;
                    break;
                default:
                    $terms[] = $token;
                    break;
            }
        }

        return [
            'terms' => array_values(array_filter($terms, static fn ($v): bool => $v !== '')),
            'name' => array_values(array_filter($names, static fn ($v): bool => $v !== '')),
            'email' => array_values(array_filter($emails, static fn ($v): bool => $v !== '')),
            'id' => array_values(array_unique($ids)),
            'role' => array_values(array_filter($roles, static fn ($v): bool => $v !== '')),
        ];
    }

    /**
     * @return list<string>
     */
    private function tokenize(string $query): array
    {
        /** @var list<string> $matches */
        $matches = [];
        if (preg_match_all('/(?:[^\s"\']+|"[^"]*"|\'[^\']*\')+/u', $query, $found) > 0) {
            /** @var list<string> $matches */
            $matches = array_map('strval', $found[0]);
        }

        $tokens = [];
        foreach ($matches as $raw) {
            $token = trim($raw);
            if ($token === '') {
                continue;
            }
            $tokens[] = $token;
        }

        return $tokens;
    }

    private function stripQuotes(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $first = $value[0];
        $last = $value[strlen($value) - 1];

        if (($first === '"' && $last === '"') || ($first === '\'' && $last === '\'')) {
            return substr($value, 1, -1);
        }

        return $value;
    }

    /**
     * @param  non-empty-string  $key
     */
    private static function parseIntQuery(Request $request, string $key, int $default, int $min, int $max): int
    {
        /** @var array<array-key,mixed>|string|null $raw */
        $raw = $request->query($key);

        $val = $default;

        if (\is_string($raw) && $raw !== '' && \preg_match('/^\d+$/', $raw) === 1) {
            $val = (int) $raw;
        } elseif (\is_array($raw)) {
            /** @var list<string> $stringVals */
            $stringVals = \array_values(\array_filter(
                $raw,
                static fn ($x): bool => \is_string($x)
            ));
            $first = $stringVals[0] ?? null; // string|null
            if ($first !== null && $first !== '' && \preg_match('/^\d+$/', $first) === 1) {
                $val = (int) $first;
            }
        }

        if ($val < $min) {
            $val = $min;
        }
        if ($val > $max) {
            $val = $max;
        }

        return $val;
    }
}
