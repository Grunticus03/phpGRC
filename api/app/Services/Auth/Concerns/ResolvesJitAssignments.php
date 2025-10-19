<?php

declare(strict_types=1);

namespace App\Services\Auth\Concerns;

/**
 * Shared helpers for resolving Just-In-Time provisioning settings and role assignments.
 */
trait ResolvesJitAssignments
{
    /**
     * @param  array<string,mixed>  $config
     * @return array{create_users:bool,default_roles:list<string>,role_templates:list<array{claim:string,values:list<string>,roles:list<string>}>}
     */
    private function resolveJitSettings(array $config): array
    {
        $defaults = [
            'create_users' => true,
            'default_roles' => [],
            'role_templates' => [],
        ];

        if (! isset($config['jit']) || ! is_array($config['jit'])) {
            return $defaults;
        }

        /** @var array<string,mixed> $jit */
        $jit = $config['jit'];

        $createUsers = $defaults['create_users'];
        if (array_key_exists('create_users', $jit)) {
            if (is_bool($jit['create_users'])) {
                $createUsers = $jit['create_users'];
            } elseif (is_string($jit['create_users']) || is_int($jit['create_users'])) {
                $coerced = filter_var($jit['create_users'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($coerced !== null) {
                    $createUsers = $coerced;
                }
            }
        }

        $defaultRoles = [];
        if (isset($jit['default_roles']) && is_array($jit['default_roles'])) {
            foreach ($jit['default_roles'] as $rawRole) {
                if (! is_string($rawRole)) {
                    continue;
                }

                $normalizedRole = strtolower(trim($rawRole));
                if ($normalizedRole === '') {
                    continue;
                }

                $defaultRoles[] = $normalizedRole;
            }

            $defaultRoles = array_values(array_unique($defaultRoles));
        }

        $roleTemplates = [];
        if (isset($jit['role_templates']) && is_array($jit['role_templates'])) {
            foreach ($jit['role_templates'] as $template) {
                if (! is_array($template)) {
                    continue;
                }

                $claimRaw = $template['claim'] ?? null;
                if (! is_string($claimRaw) || trim($claimRaw) === '') {
                    continue;
                }

                $claim = trim($claimRaw);

                /** @var array<array-key, mixed>|string|null $valuesRaw */
                $valuesRaw = $template['values'] ?? ($template['value'] ?? null);
                $values = [];
                if (is_string($valuesRaw)) {
                    $values[] = trim($valuesRaw);
                } elseif (is_array($valuesRaw)) {
                    /** @var list<mixed> $valuesArray */
                    $stringValues = array_filter(
                        $valuesRaw,
                        static fn ($item): bool => is_string($item)
                    );
                    /** @var list<string> $valuesArray */
                    $valuesArray = array_values($stringValues);
                    foreach ($valuesArray as $candidate) {
                        $normalized = trim($candidate);
                        if ($normalized === '') {
                            continue;
                        }

                        $values[] = $normalized;
                    }
                }

                $values = array_values(array_unique(array_filter(
                    $values,
                    static fn (string $value): bool => $value !== ''
                )));

                if ($values === []) {
                    continue;
                }

                if (! isset($template['roles']) || ! is_array($template['roles'])) {
                    continue;
                }

                $roles = [];
                $stringRoles = array_filter(
                    $template['roles'],
                    static fn ($item): bool => is_string($item)
                );
                /** @var list<string> $roleCandidates */
                $roleCandidates = array_values($stringRoles);
                foreach ($roleCandidates as $roleId) {
                    $normalizedRole = strtolower(trim($roleId));
                    if ($normalizedRole === '') {
                        continue;
                    }

                    $roles[] = $normalizedRole;
                }

                $roles = array_values(array_unique($roles));
                if ($roles === []) {
                    continue;
                }

                $roleTemplates[] = [
                    'claim' => $claim,
                    'values' => $values,
                    'roles' => $roles,
                ];
            }
        }

        return [
            'create_users' => $createUsers,
            'default_roles' => $defaultRoles,
            'role_templates' => $roleTemplates,
        ];
    }

    /**
     * @param  array{create_users:bool,default_roles:list<string>,role_templates:list<array{claim:string,values:list<string>,roles:list<string>}>}  $jit
     * @param  callable(string): mixed  $valueResolver
     * @return list<string>
     */
    private function resolveRoles(array $jit, callable $valueResolver): array
    {
        $roles = $jit['default_roles'];

        foreach ($jit['role_templates'] as $template) {
            /** @var mixed $claimValue */
            $claimValue = $valueResolver($template['claim']);
            if ($claimValue === null) {
                continue;
            }

            if ($this->valuesMatch($claimValue, $template['values'])) {
                $roles = array_merge($roles, $template['roles']);
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * @param  list<string>  $expectedValues
     */
    private function valuesMatch(mixed $claimValue, array $expectedValues): bool
    {
        $expected = array_values(array_unique(array_filter(
            array_map(
                static fn (string $value): string => mb_strtolower(trim($value)),
                $expectedValues
            ),
            static fn (string $value): bool => $value !== ''
        )));

        if ($expected === []) {
            return false;
        }

        if (is_string($claimValue)) {
            return in_array(mb_strtolower($claimValue), $expected, true);
        }

        if (is_array($claimValue)) {
            /** @var array<array-key, mixed> $claimValue */
            foreach ($claimValue as $candidate) {
                if (! is_string($candidate)) {
                    continue;
                }

                if (in_array(mb_strtolower($candidate), $expected, true)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
