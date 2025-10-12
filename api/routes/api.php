<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\EvidencePurgeController;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Admin\UsersController;
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
use App\Http\Controllers\Metrics\MetricsController;
use App\Http\Controllers\OpenApiController;
use App\Http\Controllers\Rbac\PolicyController;
use App\Http\Controllers\Rbac\RolesController;
use App\Http\Controllers\Rbac\UserRolesController;
use App\Http\Controllers\Rbac\UserSearchController;
use App\Http\Controllers\Reports\AdminActivityReportController;
use App\Http\Controllers\Settings\BrandAssetsController;
use App\Http\Controllers\Settings\BrandProfilesController;
use App\Http\Controllers\Settings\DesignerThemesController;
use App\Http\Controllers\Settings\ThemePacksController;
use App\Http\Controllers\Settings\UiSettingsController as UiSettingsApiController;
use App\Http\Controllers\Settings\UiThemeManifestController;
use App\Http\Controllers\User\UiPreferencesController;
use App\Http\Middleware\Auth\BruteForceGuard;
use App\Http\Middleware\Auth\RequireSanctumWhenRequired;
use App\Http\Middleware\Auth\TokenCookieGuard;
use App\Http\Middleware\BreakGlassGuard;
use App\Http\Middleware\GenericRateLimit;
use App\Http\Middleware\RbacMiddleware;
use App\Http\Middleware\SetupGuard;
use App\Services\Settings\SettingsService;
use Illuminate\Support\Facades\Route;

Route::aliasMiddleware('auth.cookie', TokenCookieGuard::class);
Route::aliasMiddleware('auth.require_sanctum', RequireSanctumWhenRequired::class);

/*
 |--------------------------------------------------------------------------
 | Setup
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
Route::get('/health/fingerprint', function (SettingsService $settings) {
    $eff = $settings->effectiveConfig();
    $summary = [
        'rbac' => [
            'enabled' => (bool) ($eff['core']['rbac']['enabled'] ?? false),
            'require_auth' => \App\Support\ConfigBoolean::value('core.rbac.require_auth', false),
            'roles_count' => count((array) ($eff['core']['rbac']['roles'] ?? [])),
        ],
        'audit' => [
            'enabled' => (bool) ($eff['core']['audit']['enabled'] ?? false),
            'retention_days' => (int) ($eff['core']['audit']['retention_days'] ?? 0),
        ],
        'evidence' => [
            'enabled' => (bool) ($eff['core']['evidence']['enabled'] ?? false),
            'max_mb' => (int) ($eff['core']['evidence']['max_mb'] ?? 0),
            'allowed_mime_count' => count((array) ($eff['core']['evidence']['allowed_mime'] ?? [])),
        ],
        'avatars' => [
            'enabled' => (bool) ($eff['core']['avatars']['enabled'] ?? false),
            'size_px' => (int) ($eff['core']['avatars']['size_px'] ?? 0),
            'format' => (string) ($eff['core']['avatars']['format'] ?? ''),
        ],
        'api_throttle' => [
            'enabled' => (bool) ($eff['core']['api']['throttle']['enabled'] ?? config('core.api.throttle.enabled', false)),
            'strategy' => (string) ($eff['core']['api']['throttle']['strategy'] ?? config('core.api.throttle.strategy', 'ip')),
            'window_seconds' => (int) ($eff['core']['api']['throttle']['window_seconds'] ?? (int) config('core.api.throttle.window_seconds', 60)),
            'max_requests' => (int) ($eff['core']['api']['throttle']['max_requests'] ?? (int) config('core.api.throttle.max_requests', 30)),
        ],
    ];
    $meta = (array) config('phpgrc.overlay', ['loaded' => false, 'path' => null, 'mtime' => null]);

    return response()->json([
        'ok' => true,
        'fingerprint' => 'sha256:'.hash('sha256', json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
        'overlay' => [
            'loaded' => (bool) ($meta['loaded'] ?? false),
            'path' => $meta['path'] ?? null,
            'mtime' => $meta['mtime'] ?? null,
        ],
        'summary' => $summary,
    ], 200);
});

/*
 |--------------------------------------------------------------------------
 | OpenAPI + Redoc UI
 |--------------------------------------------------------------------------
*/
Route::get('/openapi.yaml', [OpenApiController::class, 'yaml']);
Route::get('/openapi.json', [OpenApiController::class, 'json']);
Route::get('/docs', function () {
    $html = <<<'HTML'
<!doctype html><html lang="en"><head><meta charset="utf-8"><title>phpGRC API</title><meta name="viewport" content="width=device-width,initial-scale=1"><style>html,body,redoc{height:100%}body{margin:0}</style></head><body><redoc spec-url="/api/openapi.json"></redoc><script src="https://cdn.redoc.ly/redoc/latest/bundles/redoc.standalone.js"></script></body></html>
HTML;

    return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
});

/*
 |--------------------------------------------------------------------------
 | Auth (token)
 |--------------------------------------------------------------------------
*/
Route::post('/auth/login', [LoginController::class,  'login'])->middleware(BruteForceGuard::class);
Route::post('/auth/logout', [LogoutController::class, 'logout'])->middleware(['auth.cookie', 'auth:sanctum']);
Route::get('/auth/me', [MeController::class,     'me'])->middleware(['auth.cookie', 'auth:sanctum']);

Route::post('/auth/totp/enroll', [TotpController::class, 'enroll']);
Route::post('/auth/totp/verify', [TotpController::class, 'verify']);

/*
 |--------------------------------------------------------------------------
 | Break-glass
 |--------------------------------------------------------------------------
*/
Route::post('/auth/break-glass', [BreakGlassController::class, 'invoke'])->middleware(BreakGlassGuard::class);

/*
 |--------------------------------------------------------------------------
 | RBAC stack (leave Sanctum off here; middleware selects the guard)
 |--------------------------------------------------------------------------
*/
$rbacStack = ['auth.cookie', RbacMiddleware::class];

/*
 |--------------------------------------------------------------------------
 | Admin Settings + Users
 |--------------------------------------------------------------------------
*/
Route::prefix('/admin')
    ->middleware($rbacStack)
    ->group(function (): void {
        Route::match(['GET', 'HEAD'], '/settings', [SettingsController::class, 'index'])
            ->defaults('policy', 'core.settings.manage');
        Route::post('/settings', [SettingsController::class, 'update'])
            ->defaults('policy', 'core.settings.manage');
        Route::put('/settings', [SettingsController::class, 'update'])
            ->defaults('policy', 'core.settings.manage');
        Route::patch('/settings', [SettingsController::class, 'update'])
            ->defaults('policy', 'core.settings.manage');

        Route::post('/evidence/purge', EvidencePurgeController::class)
            ->defaults('policy', 'core.evidence.manage');
    });

Route::get('/settings/ui/themes', UiThemeManifestController::class)
    ->middleware(['auth.cookie', 'auth.require_sanctum']);

Route::get('/settings/ui', [UiSettingsApiController::class, 'show'])
    ->middleware(['auth.cookie', 'auth.require_sanctum']);

Route::prefix('/settings/ui')
    ->middleware($rbacStack)
    ->group(function (): void {
        Route::put('/', [UiSettingsApiController::class, 'update'])
            ->defaults('policy', 'core.settings.manage');

        Route::get('/brand-profiles', [BrandProfilesController::class, 'index'])
            ->defaults('policy', 'core.settings.manage');
        Route::post('/brand-profiles', [BrandProfilesController::class, 'store'])
            ->defaults('policy', 'core.settings.manage');
        Route::put('/brand-profiles/{profile}', [BrandProfilesController::class, 'update'])
            ->defaults('policy', 'core.settings.manage');
        Route::post('/brand-profiles/{profile}/activate', [BrandProfilesController::class, 'activate'])
            ->defaults('policy', 'core.settings.manage');

        Route::get('/brand-assets', [BrandAssetsController::class, 'index'])
            ->defaults('policy', 'core.settings.manage');
        Route::post('/brand-assets', [BrandAssetsController::class, 'store'])
            ->defaults('policy', 'core.settings.manage');
        Route::delete('/brand-assets/{asset}', [BrandAssetsController::class, 'destroy'])
            ->defaults('policy', 'core.settings.manage');

        Route::post('/themes/import', [ThemePacksController::class, 'import'])
            ->middleware(GenericRateLimit::class)
            ->defaults('throttle', ['strategy' => 'user', 'window_seconds' => 600, 'max_requests' => 5])
            ->defaults('policy', 'core.settings.manage');
        Route::put('/themes/{slug}', [ThemePacksController::class, 'update'])
            ->defaults('policy', 'core.settings.manage');
        Route::delete('/themes/{slug}', [ThemePacksController::class, 'destroy'])
            ->defaults('policy', 'core.settings.manage');
        Route::get('/designer/themes', [DesignerThemesController::class, 'index'])
            ->defaults('policy', 'core.settings.manage');
        Route::post('/designer/themes', [DesignerThemesController::class, 'store'])
            ->defaults('policy', 'core.settings.manage');
        Route::delete('/designer/themes/{slug}', [DesignerThemesController::class, 'destroy'])
            ->defaults('policy', 'core.settings.manage');
    });

Route::get('/settings/ui/brand-assets/{asset}/download', [BrandAssetsController::class, 'download']);

Route::prefix('/me')
    ->middleware(['auth.cookie', 'auth.require_sanctum'])
    ->group(function (): void {
        Route::get('/prefs/ui', [UiPreferencesController::class, 'show']);
        Route::put('/prefs/ui', [UiPreferencesController::class, 'update']);
    });

Route::prefix('/users')
    ->middleware($rbacStack)
    ->group(function (): void {
        Route::get('/', [UsersController::class, 'index'])
            ->defaults('policy', 'core.users.view');
        Route::post('/', [UsersController::class, 'store'])
            ->defaults('policy', 'core.users.manage');
        Route::get('/{user}', [UsersController::class, 'show'])
            ->whereNumber('user')
            ->defaults('policy', 'core.users.view');
        Route::put('/{user}', [UsersController::class, 'update'])
            ->whereNumber('user')
            ->defaults('policy', 'core.users.manage');
        Route::delete('/{user}', [UsersController::class, 'destroy'])
            ->whereNumber('user')
            ->defaults('policy', 'core.users.manage');
    });

/*
 |--------------------------------------------------------------------------
 | Dashboard KPIs
 |--------------------------------------------------------------------------
*/
$metricsStack = $rbacStack;

Route::prefix('/dashboard')
    ->middleware($metricsStack)
    ->group(function (): void {
        Route::get('/kpis', [MetricsController::class, 'kpis'])
            ->middleware(GenericRateLimit::class)
            ->defaults('throttle', ['strategy' => 'user', 'window_seconds' => 60, 'max_requests' => 20])
            ->defaults('policy', 'core.metrics.view');
    });

/*
 |--------------------------------------------------------------------------
 | Metrics alias
 |--------------------------------------------------------------------------
*/
Route::prefix('/metrics')
    ->middleware($metricsStack)
    ->group(function (): void {
        Route::get('/dashboard', [MetricsController::class, 'index'])
            ->middleware(GenericRateLimit::class)
            ->defaults('throttle', ['strategy' => 'user', 'window_seconds' => 60, 'max_requests' => 20])
            ->defaults('policy', 'core.metrics.view');
    });

/*
 |--------------------------------------------------------------------------
 | Reports
 |--------------------------------------------------------------------------
*/
Route::prefix('/reports')
    ->middleware($rbacStack)
    ->group(function (): void {
        Route::match(['GET', 'HEAD'], '/admin-activity', AdminActivityReportController::class)
            ->middleware(GenericRateLimit::class)
            ->defaults('throttle', ['strategy' => 'user', 'window_seconds' => 60, 'max_requests' => 20])
            ->defaults('policy', 'core.reports.view');
    });

/*
 |--------------------------------------------------------------------------
 | Exports
 |--------------------------------------------------------------------------
*/
Route::prefix('/exports')
    ->middleware($rbacStack)
    ->group(function (): void {
        Route::post('/{type}', [ExportController::class, 'createType'])
            ->middleware(GenericRateLimit::class)
            ->defaults('throttle', ['strategy' => 'user', 'window_seconds' => 60, 'max_requests' => 5])
            ->defaults('capability', 'core.exports.generate')
            ->defaults('policy', 'core.exports.generate');
        Route::post('/', [ExportController::class, 'create'])
            ->middleware(GenericRateLimit::class)
            ->defaults('throttle', ['strategy' => 'user', 'window_seconds' => 60, 'max_requests' => 5])
            ->defaults('capability', 'core.exports.generate')
            ->defaults('policy', 'core.exports.generate');

        Route::get('/{jobId}/status', [StatusController::class, 'show'])
            ->defaults('policy', 'core.exports.generate');
        Route::get('/{jobId}/download', [ExportController::class, 'download'])
            ->defaults('policy', 'core.exports.generate');
    });

/*
 |--------------------------------------------------------------------------
 | RBAC roles + user-role assignment
 |--------------------------------------------------------------------------
*/
Route::prefix('/rbac')
    ->middleware($rbacStack)
    ->group(function (): void {
        Route::match(['GET', 'HEAD'], '/roles', [RolesController::class, 'index'])
            ->defaults('policy', 'rbac.roles.manage');
        Route::post('/roles', [RolesController::class, 'store'])
            ->defaults('policy', 'rbac.roles.manage');
        Route::patch('/roles/{role}', [RolesController::class, 'update'])
            ->where('role', '.*')
            ->defaults('policy', 'rbac.roles.manage');
        Route::delete('/roles/{role}', [RolesController::class, 'destroy'])
            ->where('role', '.*')
            ->defaults('policy', 'rbac.roles.manage');

        Route::match(['GET', 'HEAD'], '/users/{user}/roles', [UserRolesController::class, 'show'])
            ->whereNumber('user')
            ->defaults('policy', 'rbac.user_roles.manage');
        Route::put('/users/{user}/roles', [UserRolesController::class, 'replace'])
            ->whereNumber('user')
            ->defaults('policy', 'rbac.user_roles.manage');
        Route::post('/users/{user}/roles/{role}', [UserRolesController::class, 'attach'])
            ->whereNumber('user')
            ->defaults('policy', 'rbac.user_roles.manage');
        Route::delete('/users/{user}/roles/{role}', [UserRolesController::class, 'detach'])
            ->whereNumber('user')
            ->defaults('policy', 'rbac.user_roles.manage');

        Route::get('/policies/effective', [PolicyController::class, 'effective'])
            ->defaults('policy', 'core.rbac.view');

        // Require auth only when configured; cookie guard still injects bearer tokens.
        Route::get('/users/search', [UserSearchController::class, 'index'])
            ->middleware('auth.cookie')
            ->middleware('auth.require_sanctum')
            ->middleware(GenericRateLimit::class)
            ->defaults('throttle', ['strategy' => 'user', 'window_seconds' => 60, 'max_requests' => 30])
            ->defaults('policy', 'rbac.user_roles.manage');
    });

/*
 |--------------------------------------------------------------------------
 | Audit trail
 |--------------------------------------------------------------------------
*/
Route::match(['GET', 'HEAD'], '/audit', [AuditController::class, 'index'])
    ->middleware($rbacStack)
    ->defaults('policy', 'core.audit.view');

Route::get('/audit/categories', [AuditController::class, 'categories'])
    ->middleware($rbacStack)
    ->defaults('policy', 'core.audit.view');

Route::get('/audit/export.csv', [AuditExportController::class, 'exportCsv'])
    ->middleware($rbacStack)
    ->middleware(GenericRateLimit::class)
    ->defaults('throttle', ['strategy' => 'ip', 'window_seconds' => 60, 'max_requests' => 5])
    ->defaults('policy', 'core.audit.view')
    ->defaults('capability', 'core.audit.export');

/*
 |--------------------------------------------------------------------------
 | Evidence
 |--------------------------------------------------------------------------
*/
Route::prefix('/evidence')
    ->middleware($rbacStack)
    ->group(function (): void {
        Route::match(['GET', 'HEAD'], '/', [EvidenceController::class, 'index'])
            ->defaults('policy', 'core.evidence.view');

        Route::post('/', [EvidenceController::class, 'store'])
            ->middleware(GenericRateLimit::class)
            ->defaults('throttle', ['strategy' => 'user', 'window_seconds' => 60, 'max_requests' => 10])
            ->defaults('policy', 'core.evidence.manage')
            ->defaults('capability', 'core.evidence.upload');

        Route::match(['GET', 'HEAD'], '/{id}', [EvidenceController::class, 'show'])
            ->middleware(GenericRateLimit::class)
            ->defaults('throttle', ['strategy' => 'ip', 'window_seconds' => 60, 'max_requests' => 120])
            ->defaults('policy', 'core.evidence.view');

        Route::delete('/{id}', [EvidenceController::class, 'destroy'])
            ->middleware(GenericRateLimit::class)
            ->defaults('throttle', ['strategy' => 'user', 'window_seconds' => 60, 'max_requests' => 10])
            ->defaults('policy', 'core.evidence.manage')
            ->defaults('capability', 'core.evidence.delete');
    });

/*
 |--------------------------------------------------------------------------
 | Avatars scaffold
 |--------------------------------------------------------------------------
*/
Route::post('/avatar', [AvatarController::class, 'store']);
Route::match(['GET', 'HEAD'], '/avatar/{user}', [AvatarController::class, 'show'])->whereNumber('user');
