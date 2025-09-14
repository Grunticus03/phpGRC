<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;

final class ConfigOverlayServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $path = (string) config('core.setup.shared_config_path', '/opt/phpgrc/shared/config.php');
        $meta = ['loaded' => false, 'path' => null, 'mtime' => null];

        if (is_file($path) && is_readable($path)) {
            /** @var mixed $raw */
            $raw = require $path;
            if (is_array($raw)) {
                $this->mergeCoreOverlay($raw);
                $meta['loaded'] = true;
                $meta['path']   = $path;
                $meta['mtime']  = @filemtime($path) ?: null;
            }
        }

        // Expose non-sensitive overlay meta for health fingerprint.
        config()->set('phpgrc.overlay', $meta);
    }

    /**
     * Merge selected keys from overlay into config('core') with overlay precedence.
     *
     * @param array<string,mixed> $overlay
     */
    private function mergeCoreOverlay(array $overlay): void
    {
        /** @var array<string,mixed> $core */
        $core = (array) config('core', []);

        /** @var array<string,mixed> $oCore */
        $oCore = (array) Arr::get($overlay, 'core', []);

        // Only allow explicit contract keys to override.
        $allowed = [
            'rbac.enabled',
            'rbac.require_auth',
            'rbac.roles',
            'rbac.mode',
            'rbac.persistence',

            'audit.enabled',
            'audit.retention_days',

            'evidence.enabled',
            'evidence.max_mb',
            'evidence.allowed_mime',

            'avatars.enabled',
            'avatars.size_px',
            'avatars.format',

            'capabilities.core.exports.generate',
            'exports.enabled',
            'exports.disk',
            'exports.dir',
        ];

        foreach ($allowed as $dot) {
            if (Arr::has($oCore, $dot)) {
                Arr::set($core, $dot, Arr::get($oCore, $dot));
            }
        }

        // Normalize types for booleans and integers commonly passed as strings.
        $bools = [
            'rbac.enabled', 'rbac.require_auth', 'audit.enabled',
            'evidence.enabled', 'avatars.enabled', 'exports.enabled',
            'capabilities.core.exports.generate',
        ];
        foreach ($bools as $b) {
            if (Arr::has($core, $b)) {
                Arr::set($core, $b, filter_var(Arr::get($core, $b), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false);
            }
        }

        $ints = ['audit.retention_days', 'evidence.max_mb', 'avatars.size_px'];
        foreach ($ints as $i) {
            if (Arr::has($core, $i)) {
                Arr::set($core, $i, (int) Arr::get($core, $i));
            }
        }

        // Ensure roles is an array of strings.
        $roles = (array) Arr::get($core, 'rbac.roles', []);
        $roles = array_values(array_filter(array_map(
            fn ($v) => is_string($v) ? trim($v) : null,
            $roles
        ), fn ($v) => $v !== null && $v !== ''));
        Arr::set($core, 'rbac.roles', $roles);

        config()->set('core', $core);
    }
}
