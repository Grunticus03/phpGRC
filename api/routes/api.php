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
use App\Http\Controllers\OpenApiController;
use App\Http\Controllers\Rbac\RolesController;
use App\Http\Controllers\Rbac\UserRolesController;
use App\Http\Middleware\BreakGlassGuard;
use App\Http\Middleware\RbacMiddleware;
use App\Http\Middleware\SetupGuard;
use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 | Reserved setup paths wired (Phase 4 bugfix)
 |--------------------------------------------------------------------------
 | GET  /api/setup/status
 | POST /api/setup/db/test
 | POST /api/setup/db/write
 | POST /api/setup/app-key
 | POST /api/setup/schema/init
 | POST /api/setup/admin
 | POST /api/setup/admin/totp/verify
 | POST /api/setup/smtp
 | POST /api/setup/idp
 | POST /api/setup/branding
 | POST /api/setup/finish
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

/*
 |--------------------------------------------------------------------------
 | OpenAPI spec
 |--------------------------------------------------------------------------
*/
Route::match(['GET', 'HEAD'], '/openapi.yaml', [OpenApiController::class, 'yaml']);

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
 | Build RBAC stack conditionally.
 | - Include class-based middleware only if it exists.
 | - Prepend auth:sanctum when require_auth=true.
 |--------------------------------------------------------------------------
*/
$rbacStack = [];
if (class_exists(RbacMiddleware::class)) {
    $rbacStack[] = RbacMiddleware::class;
}
if (config('core.rbac.require_auth', false)) {
    array_unshift($rbacStack, 'auth:sanctum');
}

/*
 |--------------------------------------------------------------------------
 | Admin Settings (gated by RBAC when present/enabled)
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
 | Exports (Phase 4) — enforce RBAC when present; creation also gated by capability
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
 | RBAC routes registered only if controllers exist
 |--------------------------------------------------------------------------
*/
if (class_exists(RolesController::class) && class_exists(UserRolesController::class)) {
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
}

/*
 |--------------------------------------------------------------------------
 | Audit trail (view limited when enabled)
 |--------------------------------------------------------------------------
*/
Route::match(['GET','HEAD'], '/audit', [AuditController::class, 'index'])
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
