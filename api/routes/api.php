<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Audit\AuditController;
use App\Http\Controllers\Audit\AuditExportController;
use App\Http\Controllers\Auth\BreakGlassController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\TotpController;
use App\Http\Controllers\Avatar\AvatarController;
use App\Http\Controllers\Evidence\EvidenceController;
use App\Http\Controllers\Export\ExportController;
use App\Http\Controllers\Export\StatusController;
use App\Http\Controllers\Rbac\RolesController;
use App\Http\Controllers\Rbac\UserRolesController;
use App\Http\Controllers\OpenApiController;
use App\Http\Middleware\BreakGlassGuard;
use App\Http\Middleware\RbacMiddleware;
use App\Http\Middleware\SetupGuard;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 | Reserved setup paths wired (Phase 4 bugfix)
 |--------------------------------------------------------------------------
*/
Route::prefix('/setup')
    ->middleware([SetupGuard::class])
    ->group(function (): void {
        Route::get('/status', [\App\Http\Controllers\Setup\SetupStatusController::class, 'show']);
        Route::post('/db/test', [\App\Http\Controllers\Setup\DbController::class, 'test']);
        Route::post('/db/write', [\App\Http\Controllers\Setup\DbController::class, 'write']);
        Route::post('/app-key', [\App\Http\Controllers\Setup\AppKeyController::class, 'generate']);
        Route::post('/schema/init', [\App\Http\Controllers\Setup\SchemaController::class, 'init']);
        Route::post('/admin', [\App\Http\Controllers\Setup\AdminController::class, 'create']);
        Route::post('/admin/totp/verify', [\App\Http\Controllers\Setup\AdminMfaController::class, 'verify']);
        Route::post('/smtp', [\App\Http\Controllers\Setup\SmtpController::class, 'store']);
        Route::post('/idp', [\App\Http\Controllers\Setup\IdpController::class, 'store']);
        Route::post('/branding', [\App\Http\Controllers\Setup\BrandingController::class, 'store']);
        Route::post('/finish', [\App\Http\Controllers\Setup\FinishController::class, 'finish']);
    });

/*
 |--------------------------------------------------------------------------
 | Health
 |--------------------------------------------------------------------------
*/
Route::get('/health', fn () => response()->json(['ok' => true]));

// Redacted effective-config fingerprint for ops sanity (not in OpenAPI).
Route::get('/health/fingerprint', function (SettingsService $settings) {
    $eff = $settings->effectiveConfig(); // contract-trimmed core only

    $summary = [
        'rbac' => [
            'enabled'       => (bool) ($eff['core']['rbac']['enabled'] ?? false),
            'require_auth'  => (bool) config('core.rbac.require_auth', false),
            'roles_count'   => count((array) ($eff['core']['rbac']['roles'] ?? [])),
        ],
        'audit' => [
            'enabled'        => (bool) ($eff['core']['audit']['enabled'] ?? false),
            'retention_days' => (int) ($eff['core']['audit']['retention_days'] ?? 0),
        ],
        'evidence' => [
            'enabled'            => (bool) ($eff['core']['evidence']['enabled'] ?? false),
            'max_mb'             => (int) ($eff['core']['evidence']['max_mb'] ?? 0),
            'allowed_mime_count' => count((array) ($eff['core']['evidence']['allowed_mime'] ?? [])),
        ],
        'avatars' => [
            'enabled' => (bool) ($eff['core']['avatars']['enabled'] ?? false),
            'size_px' => (int) ($eff['core']['avatars']['size_px'] ?? 0),
            'format'  => (string) ($eff['core']['avatars']['format'] ?? ''),
        ],
    ];

    $meta = (array) config('phpgrc.overlay', ['loaded' => false, 'path' => null, 'mtime' => null]);

    return response()->json([
        'ok'          => true,
        'fingerprint' => 'sha256:' . hash('sha256', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'overlay'     => [
            'loaded' => (bool) ($meta['loaded'] ?? false),
            'path'   => $meta['path'] ?? null,
            'mtime'  => $meta['mtime'] ?? null,
        ],
        'summary'     => $summary,
    ], 200);
});

/*
 |--------------------------------------------------------------------------
 | OpenAPI spec (public) + Swagger UI
 |--------------------------------------------------------------------------
*/
Route::get('/openapi.yaml', [OpenApiController::class, 'yaml']);
Route::get('/openapi.json', [OpenApiController::class, 'json']);
Route::get('/docs', function () {
    $html = <<<'HTML'
<!doctype html>
<html>
  <head>
    <meta charset="utf-8"/>
    <title>phpGRC API Docs</title>
    <meta name="viewport" content="width=device-width, initial-scale=1"/>
    <link rel="stylesheet" href="https://unpkg.com/swagger-ui-dist@5/swagger-ui.css"/>
    <style>body{margin:0} #ui{max-width:100%}</style>
  </head>
  <body>
    <div id="ui"></div>
    <script src="https://unpkg.com/swagger-ui-dist@5/swagger-ui-bundle.js"></script>
    <script>
      window.ui = SwaggerUIBundle({
        url: '/api/openapi.yaml',
        dom_id: '#ui',
        deepLinking: true,
        presets: [SwaggerUIBundle.presets.apis],
      });
    </script>
  </body>
</html>
HTML;
    return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
});

/*
 |--------------------------------------------------------------------------
 | Auth placeholders (Phase 2 scaffolding)
 |--------------------------------------------------------------------------
*/
Route::post('/auth/login',  [LoginController::class,  'login']);
Route::post('/auth/logout', [LogoutController::class, 'logout']);
Route::get('/auth/me',      [MeController::class,     'me']);

Route::post('/auth/totp/enroll', [TotpController::class, 'enroll']);
Route::post('/auth/totp/verify', [TotpController::class, 'verify']);

/*
 |--------------------------------------------------------------------------
 | Break-glass (disabled by default)
 |--------------------------------------------------------------------------
*/
Route::post('/auth/break-glass', [BreakGlassController::class, 'invoke'])
    ->middleware(BreakGlassGuard::class);

/*
 |--------------------------------------------------------------------------
 | Build RBAC stack: auth:sanctum first when require_auth=true
 |--------------------------------------------------------------------------
*/
$rbacStack = [RbacMiddleware::class];
if (config('core.rbac.require_auth', false)) {
    array_unshift($rbacStack, 'auth:sanctum');
}

/*
 |--------------------------------------------------------------------------
 | Admin Settings (enforced by RBAC when enabled)
 |--------------------------------------------------------------------------
*/
Route::prefix('/admin')
    ->middleware($rbacStack)
    ->group(function (): void {
        Route::match(['GET','HEAD'], '/settings', [SettingsController::class, 'index'])
            ->defaults('roles', ['Admin'])
            ->defaults('policy', 'core.settings.manage');
        Route::post('/settings', [SettingsController::class, 'update'])
            ->defaults('roles', ['Admin'])
            ->defaults('policy', 'core.settings.manage');
        Route::put('/settings',  [SettingsController::class, 'update'])
            ->defaults('roles', ['Admin'])
            ->defaults('policy', 'core.settings.manage');
        Route::patch('/settings', [SettingsController::class, 'update'])
            ->defaults('roles', ['Admin'])
            ->defaults('policy', 'core.settings.manage');
    });

/*
 |--------------------------------------------------------------------------
 | Exports (Phase 4) — enforce RBAC; creation also gated by capability
 |--------------------------------------------------------------------------
*/
Route::prefix('/exports')
    ->middleware($rbacStack)
    ->group(function (): void {
        Route::post('/{type}', [ExportController::class, 'createType'])
            ->defaults('roles', ['Admin'])
            ->defaults('capability', 'core.exports.generate')
            ->defaults('policy', 'core.exports.generate');
        Route::post('/', [ExportController::class, 'create'])
            ->defaults('roles', ['Admin'])
            ->defaults('capability', 'core.exports.generate')
            ->defaults('policy', 'core.exports.generate');

        Route::get('/{jobId}/status', [StatusController::class, 'show'])
            ->defaults('roles', ['Admin', 'Auditor']);
        Route::get('/{jobId}/download', [ExportController::class, 'download'])
            ->defaults('roles', ['Admin', 'Auditor']);
    });

/*
 |--------------------------------------------------------------------------
 | RBAC roles + user-role assignment (admin-only when enabled)
 |--------------------------------------------------------------------------
*/
Route::prefix('/rbac')
    ->middleware($rbacStack)
    ->group(function (): void {
        Route::match(['GET','HEAD'], '/roles', [RolesController::class, 'index'])
            ->defaults('roles', ['Admin'])
            ->defaults('policy', 'rbac.roles.manage');
        Route::post('/roles', [RolesController::class, 'store'])
            ->defaults('roles', ['Admin'])
            ->defaults('policy', 'rbac.roles.manage');

        Route::match(['GET','HEAD'], '/users/{user}/roles', [UserRolesController::class, 'show'])
            ->whereNumber('user')
            ->defaults('roles', ['Admin'])
            ->defaults('policy', 'rbac.user_roles.manage');
        Route::put('/users/{user}/roles', [UserRolesController::class, 'replace'])
            ->whereNumber('user')
            ->defaults('roles', ['Admin'])
            ->defaults('policy', 'rbac.user_roles.manage');
        Route::post('/users/{user}/roles/{role}', [UserRolesController::class, 'attach'])
            ->whereNumber('user')
            ->defaults('roles', ['Admin'])
            ->defaults('policy', 'rbac.user_roles.manage');
        Route::delete('/users/{user}/roles/{role}', [UserRolesController::class, 'detach'])
            ->whereNumber('user')
            ->defaults('roles', ['Admin'])
            ->defaults('policy', 'rbac.user_roles.manage');
    });

/*
 |--------------------------------------------------------------------------
 | Audit trail (view limited when enabled)
 |--------------------------------------------------------------------------
*/
Route::match(['GET','HEAD'], '/audit', [AuditController::class, 'index'])
    ->middleware($rbacStack)
    ->defaults('roles', ['Admin', 'Auditor'])
    ->defaults('policy', 'core.audit.view');

Route::get('/audit/categories', [AuditController::class, 'categories'])
    ->middleware($rbacStack)
    ->defaults('roles', ['Admin', 'Auditor'])
    ->defaults('policy', 'core.audit.view');

Route::get('/audit/export.csv', [AuditExportController::class, 'exportCsv'])
    ->middleware($rbacStack)
    ->defaults('roles', ['Admin', 'Auditor'])
    ->defaults('policy', 'core.audit.view');

/*
 |--------------------------------------------------------------------------
 | Evidence (Phase 4 — persisted + retrieval)
 |--------------------------------------------------------------------------
*/
Route::prefix('/evidence')
    ->middleware($rbacStack)
    ->group(function (): void {
        Route::match(['GET','HEAD'], '/', [EvidenceController::class, 'index'])
            ->defaults('roles', ['Admin', 'Auditor'])
            ->defaults('policy', 'core.evidence.view');
        Route::post('/', [EvidenceController::class, 'store'])
            ->defaults('roles', ['Admin'])
            ->defaults('policy', 'core.evidence.manage');
        Route::match(['GET','HEAD'], '/{id}', [EvidenceController::class, 'show'])
            ->defaults('roles', ['Admin', 'Auditor'])
            ->defaults('policy', 'core.evidence.view');
    });

/*
 |--------------------------------------------------------------------------
 | Avatars scaffold (Phase 4)
 |--------------------------------------------------------------------------
*/
Route::post('/avatar', [AvatarController::class, 'store']);
Route::match(['GET','HEAD'], '/avatar/{user}', [AvatarController::class, 'show'])
    ->whereNumber('user');
