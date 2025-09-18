<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Arr;
use Illuminate\Support\ServiceProvider;

final class ConfigOverlayServiceProvider extends ServiceProvider
{
    #[\Override]
    public function register(): void
    {
        $path = (string) config('core.setup.shared_config_path', '/opt/phpgrc/shared/config.php');

        /** @var array{loaded:bool, path:null|string, mtime:null|int} $meta */
        $meta = ['loaded' => false, 'path' => null, 'mtime' => null];

        if (is_file($path) && is_readable($path)) {
            /**
             * @psalm-suppress UnresolvableInclude
             * @var mixed $raw
             */
            $raw = require $path;

            if (is_array($raw)) {
                /** @var array<string,mixed> $rawArr */
                $rawArr = $raw;
                $this->mergeCoreOverlay($rawArr);
                $meta['loaded'] = true;
                $meta['path']   = $path;

                $mtime = @filemtime($path);
                $meta['mtime'] = ($mtime === false) ? null : $mtime;
            }
        }

        config()->set('phpgrc.overlay', $meta);
    }

    /**
     * @param array<string,mixed> $overlay
     */
    private function mergeCoreOverlay(array $overlay): void
    {
        /** @var array<string,mixed> $core */
        $core = (array) config('core', []);

        /** @var array<string,mixed> $oCore */
        $oCore = (array) Arr::get($overlay, 'core', []);

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

        $bools = [
            'rbac.enabled', 'rbac.require_auth', 'audit.enabled',
            'evidence.enabled', 'avatars.enabled', 'exports.enabled',
            'capabilities.core.exports.generate',
        ];
        foreach ($bools as $b) {
            if (Arr::has($core, $b)) {
                Arr::set(
                    $core,
                    $b,
                    filter_var(Arr::get($core, $b), FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false
                );
            }
        }

        $ints = ['audit.retention_days', 'evidence.max_mb', 'avatars.size_px'];
        foreach ($ints as $i) {
            if (Arr::has($core, $i)) {
                Arr::set($core, $i, (int) Arr::get($core, $i));
            }
        }

        /** @var array<int,mixed> $rolesRaw */
        $rolesRaw = (array) Arr::get($core, 'rbac.roles', []);
        /** @var array<int,string> $roles */
        $roles = array_values(array_filter(
            array_map(
                static fn ($v): ?string => is_string($v) ? trim($v) : null,
                $rolesRaw
            ),
            static fn (?string $v): bool => $v !== null && $v !== ''
        ));
        Arr::set($core, 'rbac.roles', $roles);

        config()->set('core', $core);
    }
}

