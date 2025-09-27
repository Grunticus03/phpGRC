<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rbac;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;

final class UserSearchController extends Controller
{
    public function search(\Illuminate\Http\Request $request): \Illuminate\Http\JsonResponse
    {
        return $this->index($request);
    }

    public function index(Request $request): JsonResponse
    {
        // q: string|array|null → normalized string
        $qParam = $request->query('q'); // array|string|null
        $q = is_string($qParam)
            ? trim($qParam)
            : (is_array($qParam) ? trim((string) (array_values($qParam)[0] ?? '')) : '');

        // limit: array|string|null → normalized/clamped int
        /** @var array<array-key, mixed>|string|null $limitRaw */
        $limitRaw = $request->query('limit');
        $limit = 20;

        if (is_string($limitRaw) && $limitRaw !== '' && preg_match('/^\d+$/', $limitRaw) === 1) {
            $limit = (int) $limitRaw;
        } elseif (is_array($limitRaw)) {
            /** @var list<string> $stringVals */
            $stringVals = array_values(array_filter(
                $limitRaw,
                static fn ($x): bool => is_string($x)
            ));
            $first = $stringVals[0] ?? null; // string|null
            if ($first !== null && $first !== '' && preg_match('/^\d+$/', $first) === 1) {
                $limit = (int) $first;
            }
        }

        if ($limit < 1) { $limit = 1; }
        if ($limit > 100) { $limit = 100; }

        if ($q === '') {
            return response()->json([
                'ok'      => false,
                'code'    => 'VALIDATION_FAILED',
                'message' => 'Query is required.',
                'errors'  => ['q' => ['The q field is required.']],
            ], 422);
        }

        if (!Schema::hasTable('users')) {
            return response()->json([
                'ok'   => true,
                'note' => 'stub-only',
                'data' => [],
            ], 200);
        }

        /** @var Builder<User> $qb */
        $qb = User::query()->select(['id', 'name', 'email']);

        $like = '%' . $this->escapeLike($q) . '%';
        $qb->where(function (Builder $w) use ($like): void {
            $w->where('name', 'like', $like)
              ->orWhere('email', 'like', $like);
        });

        /** @var \Illuminate\Database\Eloquent\Collection<int,User> $rows */
        $rows = $qb->orderBy('name')->limit($limit)->get();

        /** @var list<array{id:int,name:string,email:string}> $data */
        $data = [];
        foreach ($rows as $u) {
            $data[] = [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
            ];
        }

        return response()->json([
            'ok'    => true,
            'query' => $q,
            'limit' => $limit,
            'data'  => $data,
        ], 200);
    }

    private function escapeLike(string $s): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $s);
    }
}
