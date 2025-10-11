<?php

declare(strict_types=1);

namespace App\Services\Settings;

use App\Exceptions\ThemePackException;
use App\Models\UiSetting;
use App\Models\UiThemePack;
use App\Models\UiThemePackFile;
use App\Models\UserUiPreference;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use ZipArchive;

final class ThemePackService
{
    private const MAX_FILES = 2000;
    private const MAX_DEPTH = 10;
    private const MAX_COMPRESSION_RATIO = 100;
    private const MAX_DATA_URI_BYTES = 524_288; // 512 KB

    /** @var list<string> */
    private const ALLOWED_EXTENSIONS = [
        'css',
        'scss',
        'woff2',
        'png',
        'jpg',
        'jpeg',
        'webp',
        'svg',
        'map',
        'js',
        'html',
    ];

    /** @var list<string> */
    private const INACTIVE_EXTENSIONS = ['js', 'html'];

    /** @var array<string,string> */
    private const MIME_BY_EXTENSION = [
        'css' => 'text/css',
        'scss' => 'text/x-scss',
        'woff2' => 'font/woff2',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'svg' => 'image/svg+xml',
        'map' => 'application/json',
        'js' => 'application/javascript',
        'html' => 'text/html',
    ];

    /**
     * @return array{
     *     version:string,
     *     defaults:array{dark:string,light:string},
     *     themes:list<array<string,mixed>>,
     *     packs:list<array<string,mixed>>
     * }
     */
    public function manifest(): array
    {
        $base = $this->baseManifest();
        $base['packs'] = $this->loadThemePacks();

        return $base;
    }

    public function hasTheme(string $slug): bool
    {
        $needle = trim($slug);
        if ($needle === '') {
            return false;
        }

        foreach ($this->baseManifest()['themes'] as $theme) {
            $candidate = is_array($theme) ? ($theme['slug'] ?? null) : null;
            if (is_string($candidate) && $candidate === $needle) {
                return true;
            }
        }

        foreach ($this->loadThemePacks() as $pack) {
            $candidate = $pack['slug'] ?? null;
            if (is_string($candidate) && $candidate === $needle) {
                return true;
            }
        }

        return UiThemePack::query()
            ->where('slug', $needle)
            ->where('enabled', true)
            ->exists();
    }

    /**
     * @return array{
     *     pack: UiThemePack,
     *     changes: list<array{key:string,old:mixed,new:mixed,action:string}>,
     *     files:int,
     *     affected_users:int
     * }
     */
    public function import(UploadedFile $archive, ?int $actorId = null, ?string $actorName = null): array
    {
        $realPath = $archive->getRealPath();
        if ($realPath === false) {
            throw new ThemePackException('UPLOAD_FAILED', 'Unable to read uploaded archive.', 500);
        }

        $zip = new ZipArchive();
        if ($zip->open($realPath) !== true) {
            throw new ThemePackException('THEME_IMPORT_INVALID', 'Unable to open zip archive.');
        }

        $entries = [];
        $manifestData = null;

        try {
            if ($zip->numFiles > self::MAX_FILES) {
                throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Archive contains more than %d files.', self::MAX_FILES));
            }

            $seen = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $stat = $zip->statIndex($index);
                if ($stat === false) {
                    continue;
                }

                /** @var array{name:string,index:int,crc:int,size:int,mtime:int,comp_size:int,comp_method:int,encryption_method:int} $stat */
                $name = $stat['name'];
                $normalized = $this->normalizePath($name);
                if ($normalized === null) {
                    throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Invalid archive entry path: %s', $name));
                }
                if ($this->pathDepth($normalized) > self::MAX_DEPTH) {
                    throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Entry exceeds maximum depth (%d): %s', self::MAX_DEPTH, $normalized));
                }
                if (isset($seen[$normalized])) {
                    throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Duplicate entry detected: %s', $normalized));
                }
                $seen[$normalized] = true;

                if (strtolower($normalized) === 'manifest.json') {
                    $manifestContents = $zip->getFromIndex($index);
                    if (! is_string($manifestContents)) {
                        throw new ThemePackException('THEME_IMPORT_INVALID', 'Unable to read manifest.json.');
                    }

                    /** @var mixed $decoded */
                    $decoded = json_decode($manifestContents, true);
                    if (! is_array($decoded)) {
                        throw new ThemePackException('THEME_IMPORT_INVALID', 'manifest.json must be valid JSON.');
                    }
                    $manifestData = $decoded;
                    continue;
                }

                $extension = strtolower(pathinfo($normalized, PATHINFO_EXTENSION));
                if (! in_array($extension, self::ALLOWED_EXTENSIONS, true)) {
                    throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Unsupported file type: %s', $normalized));
                }

                $contents = $zip->getFromIndex($index);
                if (! is_string($contents)) {
                    throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Unable to read entry bytes: %s', $normalized));
                }

                $decodedSize = strlen($contents);
                $compressedSize = (int) $stat['comp_size'];
                if ($compressedSize > 0 && $decodedSize > $compressedSize * self::MAX_COMPRESSION_RATIO) {
                    throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Entry %s exceeds compression ratio guard.', $normalized));
                }

                $entries[$normalized] = $this->sanitizeEntry($normalized, $extension, $contents);
            }
        } finally {
            $zip->close();
        }

        if (! is_array($manifestData)) {
            throw new ThemePackException('THEME_IMPORT_INVALID', 'manifest.json is required.');
        }

        $meta = $this->validateManifest($manifestData, $entries);
        if ($this->hasTheme($meta['slug'])) {
            throw new ThemePackException('CONFLICT', sprintf('Theme slug "%s" already exists.', $meta['slug']), 409);
        }

        $actor = $this->normalizeActor($actorId, $actorName);
        $inactive = $this->extractInactive($entries);
        $filesMeta = $this->buildFilesMetadata($entries);

        /** @var UiThemePack $pack */
        $pack = DB::transaction(function () use ($meta, $entries, $actor, $inactive, $filesMeta): UiThemePack {
            $record = new UiThemePack([
                'slug' => $meta['slug'],
                'name' => $meta['name'],
                'version' => $meta['version'],
                'author' => $meta['author'],
                'license_name' => $meta['license']['name'],
                'license_file' => $meta['license']['file'],
                'enabled' => true,
                'imported_by' => $actor['id'],
                'imported_by_name' => $actor['name'],
                'assets' => $meta['assets'],
                'files' => $filesMeta,
                'inactive' => $inactive,
            ]);
            $record->save();

            foreach ($entries as $path => $entry) {
                UiThemePackFile::create([
                    'pack_slug' => $meta['slug'],
                    'path' => $path,
                    'mime' => $entry['mime'],
                    'size_bytes' => $entry['size_bytes'],
                    'sha256' => $entry['sha256'],
                    'bytes' => $entry['bytes'],
                ]);
            }

            $record->refresh();

            return $record;
        });

        $changes = [
            [
                'key' => sprintf('ui.theme.pack.%s', $meta['slug']),
                'old' => null,
                'new' => [
                    'name' => $meta['name'],
                    'version' => $meta['version'],
                    'author' => $meta['author'],
                    'assets' => $meta['assets'],
                ],
                'action' => 'set',
            ],
            [
                'key' => sprintf('ui.theme.pack.%s.enabled', $meta['slug']),
                'old' => null,
                'new' => true,
                'action' => 'set',
            ],
        ];

        return [
            'pack' => $pack,
            'changes' => $changes,
            'files' => count($entries),
            'affected_users' => 0,
        ];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{
     *     pack: UiThemePack,
     *     changes: list<array{key:string,old:mixed,new:mixed,action:string}>,
     *     affected_users:int,
     *     default_reset:bool
     * }
     */
    public function update(string $slug, array $input, ?int $actorId = null): array
    {
        /** @var UiThemePack|null $pack */
        $pack = UiThemePack::query()->find($slug);
        if ($pack === null) {
            throw new ThemePackException('NOT_FOUND', 'Theme pack not found.', 404);
        }

        $changes = [];
        $affectedUsers = 0;
        $defaultReset = false;

        if (array_key_exists('name', $input)) {
            $name = $this->sanitizeName($input['name']);
            $before = $pack->getAttribute('name');
            if ($name !== $before) {
                $changes[] = [
                    'key' => sprintf('ui.theme.pack.%s.name', $slug),
                    'old' => $before,
                    'new' => $name,
                    'action' => $before === null ? 'set' : 'update',
                ];
                $pack->setAttribute('name', $name);
            }
        }

        if (array_key_exists('author', $input)) {
            $author = $this->sanitizeNullableString($input['author']);
            $before = $pack->getAttribute('author');
            if ($author !== $before) {
                $changes[] = [
                    'key' => sprintf('ui.theme.pack.%s.author', $slug),
                    'old' => $before,
                    'new' => $author,
                    'action' => $before === null ? 'set' : 'update',
                ];
                $pack->setAttribute('author', $author);
            }
        }

        if (array_key_exists('version', $input)) {
            $version = $this->sanitizeNullableString($input['version'], 64);
            $before = $pack->getAttribute('version');
            if ($version !== $before) {
                $changes[] = [
                    'key' => sprintf('ui.theme.pack.%s.version', $slug),
                    'old' => $before,
                    'new' => $version,
                    'action' => $before === null ? 'set' : 'update',
                ];
                $pack->setAttribute('version', $version);
            }
        }

        if (array_key_exists('enabled', $input)) {
            $enabled = $this->toBool($input['enabled']);
            $before = (bool) $pack->getAttribute('enabled');
            if ($enabled !== $before) {
                $pack->setAttribute('enabled', $enabled);
                $changes[] = [
                    'key' => sprintf('ui.theme.pack.%s.enabled', $slug),
                    'old' => $before,
                    'new' => $enabled,
                    'action' => 'update',
                ];

                if (! $enabled) {
                    $fallback = $this->clearThemeAssignments($slug, $actorId);
                    $affectedUsers = $fallback['affected_users'];
                    if ($fallback['default_changed']) {
                        $defaultReset = true;
                        $changes[] = [
                            'key' => 'ui.theme.default',
                            'old' => $fallback['default_old'],
                            'new' => $fallback['default_new'],
                            'action' => 'update',
                        ];
                    }
                }
            }
        }

        if ($changes === []) {
            return [
                'pack' => $pack,
                'changes' => [],
                'affected_users' => 0,
                'default_reset' => false,
            ];
        }

        $pack->save();
        $pack->refresh();

        return [
            'pack' => $pack,
            'changes' => $changes,
            'affected_users' => $affectedUsers,
            'default_reset' => $defaultReset,
        ];
    }

    /**
     * @return array{
     *     changes: list<array{key:string,old:mixed,new:mixed,action:string}>,
     *     files_removed:int,
     *     affected_users:int,
     *     default_reset:bool
     * }
     */
    public function delete(string $slug, ?int $actorId = null): array
    {
        /** @var UiThemePack|null $pack */
        $pack = UiThemePack::query()->find($slug);
        if ($pack === null) {
            throw new ThemePackException('NOT_FOUND', 'Theme pack not found.', 404);
        }

        $fallback = $this->clearThemeAssignments($slug, $actorId);

        $filesRemoved = UiThemePackFile::query()
            ->where('pack_slug', $slug)
            ->count();

        DB::transaction(static function () use ($pack): void {
            $pack->delete();
        });

        $name = $pack->getAttribute('name');
        $version = $pack->getAttribute('version');

        $changes = [
            [
                'key' => sprintf('ui.theme.pack.%s', $slug),
                'old' => [
                    'name' => is_string($name) ? $name : null,
                    'version' => is_string($version) ? $version : null,
                ],
                'new' => null,
                'action' => 'unset',
            ],
        ];

        if ($fallback['default_changed']) {
            $changes[] = [
                'key' => 'ui.theme.default',
                'old' => $fallback['default_old'],
                'new' => $fallback['default_new'],
                'action' => 'update',
            ];
        }

        return [
            'changes' => $changes,
            'files_removed' => $filesRemoved,
            'affected_users' => $fallback['affected_users'],
            'default_reset' => $fallback['default_changed'],
        ];
    }

    /**
     * @return array{
     *     version:string,
     *     defaults:array{dark:string,light:string},
     *     themes:list<array<string,mixed>>,
     *     packs:list<array<string,mixed>>
     * }
     */
    private function baseManifest(): array
    {
        /** @var array<string,mixed> $manifest */
        $manifest = (array) config('ui.manifest', []);

        $version = is_string($manifest['version'] ?? null) && $manifest['version'] !== ''
            ? $manifest['version']
            : '1.0.0';

        /** @var array<string,string> $defaults */
        $defaults = is_array($manifest['defaults'] ?? null) ? $manifest['defaults'] : [];
        $defaultDark = is_string($defaults['dark'] ?? null) && $defaults['dark'] !== ''
            ? $defaults['dark']
            : 'slate';
        $defaultLight = is_string($defaults['light'] ?? null) && $defaults['light'] !== ''
            ? $defaults['light']
            : 'flatly';

        /** @var list<array<string,mixed>> $themes */
        $themes = [];
        /** @var array<int,mixed> $themeInput */
        $themeInput = is_array($manifest['themes'] ?? null) ? $manifest['themes'] : [];
        foreach ($themeInput as $theme) {
            if (! is_array($theme)) {
                continue;
            }
            $slug = isset($theme['slug']) && is_string($theme['slug']) ? trim($theme['slug']) : '';
            $name = isset($theme['name']) && is_string($theme['name']) ? trim($theme['name']) : '';
            $source = isset($theme['source']) && is_string($theme['source']) ? trim($theme['source']) : '';
            if ($slug === '' || $name === '' || $source === '') {
                continue;
            }

            $supports = is_array($theme['supports'] ?? null) ? $theme['supports'] : [];
            $themes[] = [
                'slug' => $slug,
                'name' => $name,
                'source' => $source,
                'supports' => $supports,
            ];
        }

        return [
            'version' => $version,
            'defaults' => [
                'dark' => $defaultDark,
                'light' => $defaultLight,
            ],
            'themes' => $themes,
            'packs' => [],
        ];
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function loadThemePacks(): array
    {
        /** @var list<UiThemePack> $packs */
        $packs = UiThemePack::query()
            ->where('enabled', true)
            ->orderBy('name')
            ->get();

        $result = [];
        foreach ($packs as $pack) {
            $slug = $pack->getAttribute('slug');
            $name = $pack->getAttribute('name');
            if (! is_string($slug) || trim($slug) === '' || ! is_string($name) || trim($name) === '') {
                continue;
            }

            /** @var array<string,string>|null $assets */
            $assets = $pack->getAttribute('assets');
            $author = $pack->getAttribute('author');
            $licenseName = $pack->getAttribute('license_name');
            $licenseFile = $pack->getAttribute('license_file');

            $entry = [
                'slug' => trim($slug),
                'name' => trim($name),
                'source' => 'pack',
                'supports' => [
                    'mode' => $this->modesForAssets(is_array($assets) ? $assets : []),
                ],
                'author' => is_string($author) && trim($author) !== '' ? trim($author) : null,
                'license' => null,
            ];

            if (is_string($licenseName) && trim($licenseName) !== '' && is_string($licenseFile) && trim($licenseFile) !== '') {
                $entry['license'] = [
                    'name' => trim($licenseName),
                    'file' => trim($licenseFile),
                ];
            }

            $result[] = $entry;
        }

        return $result;
    }

    /**
     * @param  array<string,array{path:string,extension:string,mime:string,size_bytes:int,sha256:string,bytes:string}>  $entries
     * @return array{slug:string,name:string,version:string|null,author:string|null,license:array{name:string,file:string},assets:array<string,string>}
     */
    private function validateManifest(array $manifest, array $entries): array
    {
        $slug = isset($manifest['slug']) && is_string($manifest['slug']) ? trim($manifest['slug']) : '';
        if ($slug === '') {
            throw new ThemePackException('THEME_IMPORT_INVALID', 'manifest.json must include a slug.');
        }

        $normalizedSlug = Str::lower($slug);
        if (! str_contains($normalizedSlug, ':')) {
            $normalizedSlug = 'pack:'.$normalizedSlug;
        }
        if (! preg_match('/^[a-z0-9:_-]+$/', $normalizedSlug)) {
            throw new ThemePackException('THEME_IMPORT_INVALID', 'Invalid slug characters.');
        }

        $name = isset($manifest['name']) && is_string($manifest['name']) ? trim($manifest['name']) : '';
        if ($name === '') {
            throw new ThemePackException('THEME_IMPORT_INVALID', 'manifest.json must include a name.');
        }

        $version = $this->sanitizeNullableString($manifest['version'] ?? null, 64);
        $author = $this->sanitizeNullableString($manifest['author'] ?? null);

        $assetsRaw = $manifest['assets'] ?? null;
        if (! is_array($assetsRaw) || $assetsRaw === []) {
            throw new ThemePackException('THEME_IMPORT_INVALID', 'manifest.json must declare assets.');
        }

        /** @var array<string,string> $assets */
        $assets = [];
        foreach (['light', 'dark'] as $mode) {
            if (! isset($assetsRaw[$mode])) {
                continue;
            }
            $value = $assetsRaw[$mode];
            if (! is_string($value)) {
                continue;
            }
            $path = $this->normalizePath($value);
            if ($path === null || ! isset($entries[$path])) {
                throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Asset path missing: %s', $value));
            }
            $ext = $entries[$path]['extension'];
            if (! in_array($ext, ['css', 'scss'], true)) {
                throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Asset %s must be CSS or SCSS.', $path));
            }
            $assets[$mode] = $path;
        }

        if ($assets === []) {
            throw new ThemePackException('THEME_IMPORT_INVALID', 'At least one asset (light or dark) must be provided.');
        }

        $license = $manifest['license'] ?? null;
        if (! is_array($license)) {
            throw new ThemePackException('THEME_IMPORT_INVALID', 'manifest.json must include license metadata.');
        }

        $licenseName = isset($license['name']) && is_string($license['name']) ? trim($license['name']) : '';
        $licenseFileInput = isset($license['file']) && is_string($license['file']) ? $license['file'] : null;
        $licenseFile = $licenseFileInput !== null ? $this->normalizePath($licenseFileInput) : null;
        if ($licenseName === '' || $licenseFile === null || ! isset($entries[$licenseFile])) {
            throw new ThemePackException('THEME_IMPORT_INVALID', 'License must include a valid name and file present in the archive.');
        }

        return [
            'slug' => $normalizedSlug,
            'name' => mb_substr($name, 0, 160),
            'version' => $version,
            'author' => $author,
            'license' => [
                'name' => $licenseName,
                'file' => $licenseFile,
            ],
            'assets' => $assets,
        ];
    }

    /**
     * @param  array<string,array{path:string,extension:string,mime:string,size_bytes:int,sha256:string,bytes:string}>  $entries
     * @return array<string,list<string>>
     */
    private function extractInactive(array $entries): array
    {
        $inactive = [
            'html' => [],
            'js' => [],
        ];

        foreach ($entries as $path => $entry) {
            $ext = $entry['extension'];
            if (in_array($ext, self::INACTIVE_EXTENSIONS, true)) {
                $inactive[$ext][] = $path;
            }
        }

        return $inactive;
    }

    /**
     * @param  array<string,array{path:string,extension:string,mime:string,size_bytes:int,sha256:string,bytes:string}>  $entries
     * @return list<array{path:string,size:int,mime:string,sha256:string}>
     */
    private function buildFilesMetadata(array $entries): array
    {
        $meta = [];
        foreach ($entries as $entry) {
            $meta[] = [
                'path' => $entry['path'],
                'size' => $entry['size_bytes'],
                'mime' => $entry['mime'],
                'sha256' => $entry['sha256'],
            ];
        }

        return $meta;
    }

    private function normalizePath(string $path): ?string
    {
        $normalized = str_replace('\\', '/', $path);
        $normalized = preg_replace('#/{2,}#', '/', $normalized);
        if (! is_string($normalized)) {
            return null;
        }
        $normalized = trim($normalized, '/');
        if ($normalized === '') {
            return null;
        }

        $segments = explode('/', $normalized);
        $clean = [];
        foreach ($segments as $segment) {
            $trim = trim($segment);
            if ($trim === '' || $trim === '.') {
                continue;
            }
            if ($trim === '..' || str_contains($trim, ':')) {
                return null;
            }
            $clean[] = $trim;
        }

        return $clean === [] ? null : implode('/', $clean);
    }

    private function pathDepth(string $path): int
    {
        return substr_count($path, '/') + 1;
    }

    private function sanitizeEntry(string $path, string $extension, string $bytes): array
    {
        $mime = self::MIME_BY_EXTENSION[$extension] ?? 'application/octet-stream';
        $processed = $bytes;

        if (in_array($extension, ['css', 'scss'], true)) {
            $processed = $this->sanitizeCss($bytes, $path);
        } elseif ($extension === 'js') {
            $processed = $this->sanitizeJs($bytes, $path);
        } elseif ($extension === 'html') {
            $processed = $this->sanitizeHtml($bytes, $path);
        } elseif ($extension === 'svg') {
            $processed = $this->sanitizeSvg($bytes, $path);
        }

        return [
            'path' => $path,
            'extension' => $extension,
            'mime' => $mime,
            'size_bytes' => strlen($processed),
            'sha256' => hash('sha256', $processed),
            'bytes' => $processed,
        ];
    }

    private function sanitizeCss(string $contents, string $path): string
    {
        $this->guardImports($contents, $path);
        $this->guardUrls($contents, $path);

        return $contents;
    }

    private function sanitizeJs(string $contents, string $path): string
    {
        $pattern = '#\b(?:eval\s*\(|new\s+Function\s*\(|ServiceWorker|navigator\\.serviceWorker|XMLHttpRequest\s*\(|fetch\s*\(\s*[\"\](?:https?:)?//)#i';
        if (preg_match($pattern, $contents) === 1) {
            throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('JavaScript file contains disallowed APIs: %s', $path));
        }

        return $contents;
    }

    private function sanitizeHtml(string $contents, string $path): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        try {
            $dom->loadHTML($contents, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        } catch (\Throwable) {
            libxml_clear_errors();
            throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Unable to parse HTML file: %s', $path));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        foreach (['script', 'iframe', 'object', 'embed'] as $tag) {
            $nodes = $dom->getElementsByTagName($tag);
            for ($i = $nodes->length - 1; $i >= 0; $i--) {
                $node = $nodes->item($i);
                if ($node !== null && $node->parentNode !== null) {
                    $node->parentNode->removeChild($node);
                }
            }
        }

        $xpath = new \DOMXPath($dom);
        $attrs = $xpath->query('//@*');
        if ($attrs !== false) {
            /** @var \DOMAttr $attr */
            foreach ($attrs as $attr) {
                $name = strtolower($attr->name);
                if (str_starts_with($name, 'on')) {
                    $attr->ownerElement?->removeAttribute($attr->name);
                    continue;
                }

                if (in_array($name, ['src', 'href', 'data'], true) && $this->isExternalUrl($attr->value)) {
                    $attr->ownerElement?->removeAttribute($attr->name);
                }
            }
        }

        foreach ($dom->getElementsByTagName('form') as $form) {
            $action = $form->getAttribute('action');
            if ($action !== '' && $this->isExternalUrl($action)) {
                $form->removeAttribute('action');
            }
        }

        $metaTags = $dom->getElementsByTagName('meta');
        for ($i = $metaTags->length - 1; $i >= 0; $i--) {
            $node = $metaTags->item($i);
            if ($node !== null && strtolower($node->getAttribute('http-equiv')) === 'refresh') {
                $node->parentNode?->removeChild($node);
            }
        }

        $sanitized = $dom->saveHTML();
        if (! is_string($sanitized)) {
            throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Unable to serialize sanitized HTML: %s', $path));
        }

        return $sanitized;
    }

    private function sanitizeSvg(string $contents, string $path): string
    {
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        try {
            if (! $dom->loadXML($contents, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Invalid SVG content: %s', $path));
            }
        } catch (\Throwable) {
            libxml_clear_errors();
            throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Invalid SVG content: %s', $path));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($prev);
        }

        foreach ($dom->getElementsByTagName('script') as $script) {
            $script->parentNode?->removeChild($script);
        }

        foreach ($dom->getElementsByTagName('*') as $element) {
            if (! $element instanceof \DOMElement) {
                continue;
            }
            $attributes = $element->attributes;
            if ($attributes === null) {
                continue;
            }
            foreach (iterator_to_array($attributes) as $attrNode) {
                if (! $attrNode instanceof \DOMAttr) {
                    continue;
                }
                $name = strtolower($attrNode->name);
                if (str_starts_with($name, 'on')) {
                    $element->removeAttribute($attrNode->name);
                    continue;
                }
                if (in_array($name, ['href', 'xlink:href'], true) && $this->isExternalUrl($attrNode->value)) {
                    $element->removeAttribute($attrNode->name);
                }
            }
        }

        $sanitized = $dom->saveXML();
        if (! is_string($sanitized)) {
            throw new ThemePackException('THEME_IMPORT_INVALID', sprintf('Unable to serialize sanitized SVG: %s', $path));
        }

        return $sanitized;
    }

    private function guardImports(string $css, string $path): void
    {
        $matches = [];
        $count = preg_match_all('#@import\s+(?:url\()?[\
    private function guardImports(string $css, string $path): void
    {
        $matches = [];
        $count = preg_match_all('#@import\s+(?:url\()?[\
