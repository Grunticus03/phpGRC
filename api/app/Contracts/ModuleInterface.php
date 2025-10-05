<?php

declare(strict_types=1);

namespace App\Contracts;

/**
 * Purpose: Contract for phpGRC modules. Stub only in Phase 3.
 *
 * Notes:
 * - Implemented by module entrypoint or proxied by its ServiceProvider.
 * - No business logic in Phase 3; method stubs define shape only.
 */
interface ModuleInterface
{
    /**
     * Unique module short name (kebab-case), e.g., "risks".
     */
    public function name(): string;

    /**
     * Raw module manifest as associative array parsed from module.json.
     * @return array<string,mixed>
     */
    public function manifest(): array;

    /**
     * Capability identifiers provided by this module.
     * Example: ["risks.read","risks.write"].
     * @return array<int,string>
     */
    public function capabilities(): array;

    /**
     * Whether the module should be considered enabled.
     * Phase 3: return true by default; real gating later.
     */
    public function isEnabled(): bool;

    /**
     * Register bindings into the container.
     * Phase 3: stub; no bindings yet.
     */
    public function register(): void;

    /**
     * Boot the module after all providers are registered.
     * Phase 3: stub; no runtime behavior.
     */
    public function boot(): void;
}

