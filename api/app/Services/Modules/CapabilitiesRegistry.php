<?php

declare(strict_types=1);

namespace App\Services\Modules;

/**
 * Purpose: Central registry for module capabilities. Stub only in Phase 3.
 *
 * Behavior (Phase 3, non-functional):
 * - Store declared capabilities in-memory.
 * - No cross-module wiring, RBAC, or persistence.
 */
final class CapabilitiesRegistry
{
    /**
     * @var array<string, array<int, string>> map: capability => [providers...]
     */
    private array $providersByCapability = [];

    /**
     * Register capabilities for a provider.
     *
     * @param string   $provider  Module name or FQCN
     * @param string[] $caps      Capability ids (e.g., "risks.read")
     */
    public function register(string $provider, array $caps): void
    {
        foreach ($caps as $cap) {
            $this->providersByCapability[$cap] ??= [];
            if (!in_array($provider, $this->providersByCapability[$cap], true)) {
                $this->providersByCapability[$cap][] = $provider;
            }
        }
    }

    /**
     * Check if any provider offers the capability.
     */
    public function provides(string $capability): bool
    {
        return !empty($this->providersByCapability[$capability] ?? []);
    }

    /**
     * List providers for a capability.
     *
     * @return string[]
     */
    public function providers(string $capability): array
    {
        return $this->providersByCapability[$capability] ?? [];
    }

    /**
     * Return all registered capability ids.
     *
     * @return string[]
     */
    public function all(): array
    {
        return array_keys($this->providersByCapability);
    }

    /**
     * Reset registry (testing and reload scenarios).
     */
    public function clear(): void
    {
        $this->providersByCapability = [];
    }
}
