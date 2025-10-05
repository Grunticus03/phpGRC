<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserStoreRequest;
use App\Http\Requests\Admin\UserUpdateRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

final class UsersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->integer('per_page') ?: 25;

        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('id')
            ->paginate($perPage);

        /** @var list<array<string,mixed>> $data */
        $data = [];
        foreach ($users->items() as $u) {
            if (!$u instanceof User) {
                continue;
            }
            /** @var list<string> $roleNames */
            $roleNames = $u->roles->pluck('name')->filter(static fn ($v): bool => is_string($v))->values()->all();
            $data[] = [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'roles' => $roleNames,
            ];
        }

        return response()->json([
            'ok'   => true,
            'data' => $data,
            'meta' => [
                'page'        => $users->currentPage(),
                'per_page'    => $users->perPage(),
                'total'       => $users->total(),
                'total_pages' => $users->lastPage(),
            ],
        ], 200);
    }

    public function store(UserStoreRequest $request): JsonResponse
    {
        /** @var array{name:string,email:string,password:string,roles?:list<string>} $payload */
        $payload = $request->validated();

        /** @var list<string> $roleInputs */
        $roleInputs = isset($payload['roles']) ? array_map('strval', $payload['roles']) : [];

        /** @var list<string> $roleIds */
        $roleIds = $this->resolveRoleIds($roleInputs);

        /** @var User $user */
        $user = User::query()->create([
            'name'     => $payload['name'],
            'email'    => $payload['email'],
            'password' => Hash::make($payload['password']),
        ]);

        if ($roleIds !== []) {
            $user->roles()->sync($roleIds);
        }

        /** @var list<string> $rolesOut */
        $rolesOut = $user->roles()->pluck('name')->filter(static fn ($v): bool => is_string($v))->values()->all();

        return response()->json([
            'ok'   => true,
            'user' => [
                'id'    => $user->id,
                'name'  => $user->name,
                'email' => $user->email,
                'roles' => $rolesOut,
            ],
        ], 201);
    }

    public function update(UserUpdateRequest $request, int $user): JsonResponse
    {
        /** @var User $u */
        $u = User::query()->findOrFail($user);

        /** @var array{name?:string,email?:string,password?:string,roles?:list<string>} $payload */
        $payload = $request->validated();

        if (array_key_exists('name', $payload)) {
            $u->name = $payload['name'];
        }
        if (array_key_exists('email', $payload)) {
            $u->email = $payload['email'];
        }
        if (array_key_exists('password', $payload)) {
            $u->forceFill(['password' => Hash::make($payload['password'])]);
        }
        $u->save();

        if (array_key_exists('roles', $payload)) {
            /** @var list<string> $roleInputs */
            $roleInputs = array_map('strval', $payload['roles']);
            /** @var list<string> $roleIds */
            $roleIds = $this->resolveRoleIds($roleInputs);
            $u->roles()->sync($roleIds);
        }

        /** @var list<string> $rolesOut */
        $rolesOut = $u->roles()->pluck('name')->filter(static fn ($v): bool => is_string($v))->values()->all();

        return response()->json([
            'ok'   => true,
            'user' => [
                'id'    => $u->id,
                'name'  => $u->name,
                'email' => $u->email,
                'roles' => $rolesOut,
            ],
        ], 200);
    }

    public function destroy(int $user): JsonResponse
    {
        /** @var User $u */
        $u = User::query()->findOrFail($user);
        $u->roles()->detach();
        $u->delete();

        return response()->json(['ok' => true], 200);
    }

    /**
     * Resolve role IDs from a list of names or IDs.
     *
     * @param  list<string> $values
     * @return list<string>
     */
    private function resolveRoleIds(array $values): array
    {
        /** @var list<string> $ids */
        $ids = [];

        foreach ($values as $raw) {
            $v = trim($raw);
            if ($v === '') {
                continue;
            }

            /** @var null|string $byId */
            $byId = Role::query()->whereKey($v)->value('id');
            if (is_string($byId) && $byId !== '') {
                $ids[] = $byId;
                continue;
            }

            /** @var null|string $byName */
            $byName = Role::query()->where('name', $v)->value('id');
            if (is_string($byName) && $byName !== '') {
                $ids[] = $byName;
                continue;
            }

            $target = mb_strtolower($v, 'UTF-8');
            foreach (Role::query()->get(['id', 'name']) as $r) {
                $nameAttr = $r->getAttribute('name');
                $idAttr   = $r->getAttribute('id');
                if (!is_string($nameAttr) || !is_string($idAttr)) {
                    continue;
                }
                if (mb_strtolower($nameAttr, 'UTF-8') === $target) {
                    $ids[] = $idAttr;
                    break;
                }
            }
        }

        /** @var list<string> $unique */
        $unique = array_values(array_unique($ids));

        return $unique;
    }
}
