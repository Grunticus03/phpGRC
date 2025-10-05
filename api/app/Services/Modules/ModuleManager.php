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
        /** @var mixed $nameRaw */
        $nameRaw = $manifest['name'] ?? null;
        $name = is_string($nameRaw) ? $nameRaw : '';
        if ($name === '') {
            // Stub: silently ignore invalid; real code will throw.
            return;
        }

        $this->manifests[$name] = $manifest;

        /** @var mixed $enabledRaw */
        $enabledRaw = $manifest['enabled'] ?? true;
        $this->enabled[$name] = filter_var($enabledRaw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? true;

        /** @var mixed $capsRaw */
        $capsRaw = $manifest['capabilities'] ?? null;
        if (is_array($capsRaw)) {
            /** @var list<string> $capList */
            $capList = array_values(array_filter($capsRaw, 'is_string'));
            $this->capabilities->register($name, $capList);
        }
    }

    /**
     * Return raw manifest for a module.
     *
     * @return array<string,mixed>|null
     */
    public function manifest(string $name): ?array
    {
        /** @var array<string,mixed>|null $m */
        $m = $this->manifests[$name] ?? null;
        return $m;
    }

    /**
     * List known module names.
     *
     * @return list<string>
     */
    public function list(): array
    {
        /** @var list<string> $names */
        $names = array_keys($this->manifests);
        return $names;
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
        /** @var array<string,mixed> $man */
        $man = $module->manifest();

        /** @var mixed $versionRaw */
        $versionRaw = $man['version'] ?? null;
        $version = is_string($versionRaw) && $versionRaw !== '' ? $versionRaw : '0.0.0';

        /** @var list<string> $caps */
        $caps = $module->capabilities();

        $this->load([
            'name'         => $module->name(),
            'version'      => $version,
            'capabilities' => $caps,
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

