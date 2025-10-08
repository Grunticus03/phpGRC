<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UserStoreRequest;
use App\Http\Requests\Admin\UserUpdateRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Support\Audit\AuditCategories;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

final class UsersController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $perPage = $request->integer('per_page') ?: 25;

        /** @psalm-suppress TooManyTemplateParams */
        /**
         * @var LengthAwarePaginator $users
         *
         * @phpstan-var LengthAwarePaginator<int, User> $users
         */
        $users = User::query()
            ->with('roles:id,name')
            ->orderBy('id')
            ->paginate($perPage);

        /** @var list<array<string,mixed>> $data */
        $data = [];
        /** @var list<User> $items */
        $items = $users->items();
        foreach ($items as $u) {
            /** @var list<string> $roleNames */
            $roleNames = $u->roles->pluck('name')->filter(static fn ($v): bool => is_string($v))->values()->all();
            $data[] = [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'roles' => $roleNames,
            ];
        }

        return response()->json([
            'ok' => true,
            'data' => $data,
            'meta' => [
                'page' => $users->currentPage(),
                'per_page' => $users->perPage(),
                'total' => $users->total(),
                'total_pages' => $users->lastPage(),
            ],
        ], 200);
    }

    public function store(UserStoreRequest $request, AuditLogger $audit): JsonResponse
    {
        /** @var array{name:string,email:string,password:string,roles?:list<string>} $payload */
        $payload = $request->validated();

        /** @var list<string> $roleInputs */
        $roleInputs = isset($payload['roles']) ? array_map('strval', $payload['roles']) : [];

        /** @var list<string> $roleIds */
        $roleIds = $this->resolveRoleIds($roleInputs);

        /** @var User $user */
        $user = User::query()->create([
            'name' => $payload['name'],
            'email' => $payload['email'],
            'password' => Hash::make($payload['password']),
        ]);

        if ($roleIds !== []) {
            $user->roles()->sync($roleIds);
        }

        /** @var list<string> $rolesOut */
        $rolesOut = $user->roles()->pluck('name')->filter(static fn ($v): bool => is_string($v))->values()->all();

        $this->logUserAudit($request, $audit, 'rbac.user.created', $user, [
            'roles' => $rolesOut,
        ]);

        return response()->json([
            'ok' => true,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
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
            'ok' => true,
            'user' => [
                'id' => $u->id,
                'name' => $u->name,
                'email' => $u->email,
                'roles' => $rolesOut,
            ],
        ], 200);
    }

    public function destroy(Request $request, int $user, AuditLogger $audit): JsonResponse
    {
        /** @var User $u */
        $u = User::query()->findOrFail($user);

        /** @var list<string> $rolesBefore */
        $rolesBefore = $u->roles()->pluck('name')->filter(static fn ($v): bool => is_string($v))->values()->all();

        $this->logUserAudit($request, $audit, 'rbac.user.deleted', $u, [
            'roles' => $rolesBefore,
        ]);

        $u->roles()->detach();
        $u->delete();

        return response()->json(['ok' => true], 200);
    }

    /**
     * Resolve role IDs from a list of names or IDs.
     *
     * @param  list<string>  $values
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
                $idAttr = $r->getAttribute('id');
                if (! is_string($nameAttr) || ! is_string($idAttr)) {
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

    /**
     * @param  array<string,mixed>  $meta
     */
    private function logUserAudit(Request $request, AuditLogger $audit, string $action, User $target, array $meta = []): void
    {
        if (! config('core.audit.enabled', true)) {
            return;
        }
        if (! Schema::hasTable('audit_events')) {
            return;
        }

        try {
            $actorIdRaw = Auth::id();
            $actorId = is_int($actorIdRaw) ? $actorIdRaw : (is_string($actorIdRaw) && ctype_digit($actorIdRaw) ? (int) $actorIdRaw : null);

            $actorMeta = $meta;

            $actorUser = Auth::user();
            if ($actorUser instanceof User) {
                /** @var mixed $nameAttr */
                $nameAttr = $actorUser->getAttribute('name');
                if (is_string($nameAttr)) {
                    $name = trim($nameAttr);
                    if ($name !== '') {
                        $actorMeta['actor_username'] = $name;
                    }
                }
                /** @var mixed $emailAttr */
                $emailAttr = $actorUser->getAttribute('email');
                if (is_string($emailAttr)) {
                    $email = trim($emailAttr);
                    if ($email !== '') {
                        $actorMeta['actor_email'] = $email;
                    }
                }
            }

            /** @var mixed $targetNameAttr */
            $targetNameAttr = $target->getAttribute('name');
            if (is_string($targetNameAttr)) {
                $targetName = trim($targetNameAttr);
                if ($targetName !== '') {
                    $actorMeta['target_username'] = $targetName;
                }
            }
            /** @var mixed $targetEmailAttr */
            $targetEmailAttr = $target->getAttribute('email');
            if (is_string($targetEmailAttr)) {
                $targetEmail = trim($targetEmailAttr);
                if ($targetEmail !== '') {
                    $actorMeta['target_email'] = $targetEmail;
                }
            }

            /** @var mixed $entityIdRaw */
            $entityIdRaw = $target->getKey();
            if (is_int($entityIdRaw)) {
                $entityId = (string) $entityIdRaw;
            } elseif (is_string($entityIdRaw)) {
                $entityId = trim($entityIdRaw);
            } else {
                throw new \LogicException('User primary key must be scalar.');
            }
            if ($entityId === '') {
                throw new \LogicException('User primary key must be a non-empty string.');
            }

            if ($action === '') {
                throw new \LogicException('Audit action must be non-empty.');
            }

            $ipRaw = $request->ip();
            $ip = is_string($ipRaw) && $ipRaw !== '' ? $ipRaw : null;
            $uaRaw = $request->userAgent();
            $ua = is_string($uaRaw) && $uaRaw !== '' ? $uaRaw : null;

            $audit->log([
                'actor_id' => $actorId,
                'action' => $action,
                'category' => AuditCategories::RBAC,
                'entity_type' => 'user',
                'entity_id' => $entityId,
                'ip' => $ip,
                'ua' => $ua,
                'meta' => $actorMeta,
            ]);
        } catch (\Throwable) {
            // ignore audit failures
        }
    }
}
