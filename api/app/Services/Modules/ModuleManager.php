<?php

declare(strict_types=1);

namespace App\Services\Modules;

use App\Contracts\ModuleInterface;

/**
 * Purpose: Manage module lifecycle and manifests. Stub only in Phase 3.
 *
 * Responsibilities (future phases):
 * - Discover module directories and read module.json.
 * - Validate manifests against /api/module.schema.json.
 * - Track enable/disable state and dependency graph.
 * - Register ServiceProviders and populate CapabilitiesRegistry.
 *
 * Phase 3 constraints:
 * - No filesystem IO, no schema validation, no ServiceProvider booting.
 * - In-memory stubs only to establish contracts and CI shape.
 */
final class ModuleManager
{
    /**
     * @var array<string, array<string,mixed>> map: moduleName => manifest array
     */
    private array $manifests = [];

    /**
     * @var array<string, bool> map: moduleName => enabled
     */
    private array $enabled = [];

    public function __construct(
        private readonly CapabilitiesRegistry $capabilities
    ) {
    }

    /**
     * Load a manifest into the manager.
     * Phase 3: assumes validated; no schema checks here.
     *
     * @param array<string,mixed> $manifest
     */
    public function load(array $manifest): void
    {
        $name = (string)($manifest['name'] ?? '');
        if ($name === '') {
            // Stub: silently ignore invalid; real code will throw.
            return;
        }
        $this->manifests[$name] = $manifest;
        $this->enabled[$name]   = (bool)($manifest['enabled'] ?? true);

        // Register declared capabilities in the central registry.
        $caps = $manifest['capabilities'] ?? [];
        if (is_array($caps)) {
            $this->capabilities->register($name, array_values($caps));
        }
    }

    /**
     * Return raw manifest for a module.
     *
     * @return array<string,mixed>|null
     */
    public function manifest(string $name): ?array
    {
        return $this->manifests[$name] ?? null;
    }

    /**
     * List known module names.
     *
     * @return string[]
     */
    public function list(): array
    {
        return array_keys($this->manifests);
    }

    /**
     * Check if a module is enabled.
     */
    public function isEnabled(string $name): bool
    {
        return $this->enabled[$name] ?? false;
    }

    /**
     * Enable or disable a module flag in-memory.
     * Phase 3: no persistence; no dependency checks.
     */
    public function setEnabled(string $name, bool $enabled): void
    {
        if (array_key_exists($name, $this->manifests)) {
            $this->enabled[$name] = $enabled;
        }
    }

    /**
     * Attach a ModuleInterface instance.
     * Phase 3: only harvest capabilities and basic metadata.
     */
    public function attach(ModuleInterface $module): void
    {
        $this->load([
            'name'         => $module->name(),
            'version'      => $module->manifest()['version'] ?? '0.0.0',
            'capabilities' => $module->capabilities(),
            'enabled'      => $module->isEnabled(),
        ]);
    }

    /**
     * Reset manager state (testing and reload scenarios).
     */
    public function clear(): void
    {
        $this->manifests = [];
        $this->enabled   = [];
        $this->capabilities->clear();
    }
}

