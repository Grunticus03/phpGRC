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
        $defaultPath = '/opt/phpgrc/shared/config.php';
        /** @var mixed $pathRaw */
        $pathRaw = config('core.setup.shared_config_path', $defaultPath);
        $path = is_string($pathRaw) && $pathRaw !== '' ? $pathRaw : $defaultPath;

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
                /** @var mixed $cur */
                $cur = Arr::get($core, $b);
                $bool = filter_var($cur, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
                Arr::set($core, $b, $bool);
            }
        }

        $ints = ['audit.retention_days', 'evidence.max_mb', 'avatars.size_px'];
        foreach ($ints as $i) {
            if (Arr::has($core, $i)) {
                /** @var mixed $cur */
                $cur = Arr::get($core, $i);
                $int = is_int($cur) ? $cur : (is_numeric($cur) ? (int) $cur : 0);
                Arr::set($core, $i, $int);
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

