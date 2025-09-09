<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Models\User;
use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Audit\AuditController;
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
use App\Http\Middleware\BreakGlassGuard;
use App\Http\Middleware\RbacMiddleware;
use Illuminate\Support\Facades\Route;

/*
 |--------------------------------------------------------------------------
 | Reserved setup paths (Phase 1 CORE-001 — stubs only, no handlers yet)
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

/*
 |--------------------------------------------------------------------------
 | Health
 |--------------------------------------------------------------------------
*/
Route::get('/health', fn () => response()->json(['ok' => true]));

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
            ->defaults('roles', ['Admin']);
        Route::post('/settings', [SettingsController::class, 'update'])
            ->defaults('roles', ['Admin']);
        Route::put('/settings',  [SettingsController::class, 'update'])
            ->defaults('roles', ['Admin']);
        Route::patch('/settings', [SettingsController::class, 'update'])
            ->defaults('roles', ['Admin']);
    });

/*
 |--------------------------------------------------------------------------
 | Exports stubs (Phase 4)
 |--------------------------------------------------------------------------
*/
Route::prefix('/exports')->group(function (): void {
    Route::post('/{type}',           [ExportController::class, 'createType']);
    Route::post('/',                 [ExportController::class, 'create']);
    Route::get('/{jobId}/status',    [StatusController::class, 'show']);
    Route::get('/{jobId}/download',  [ExportController::class, 'download']);
});

/*
 |--------------------------------------------------------------------------
 | RBAC roles scaffold (admin-only when enabled)
 |--------------------------------------------------------------------------
*/
Route::prefix('/rbac')
    ->middleware($rbacStack)
    ->group(function (): void {
        Route::match(['GET','HEAD'], '/roles', [RolesController::class, 'index'])
            ->defaults('roles', ['Admin']);
        Route::post('/roles', [RolesController::class, 'store'])
            ->defaults('roles', ['Admin']);
    });

/*
 |--------------------------------------------------------------------------
 | Audit trail (view limited when enabled)
 |--------------------------------------------------------------------------
*/
Route::match(['GET','HEAD'], '/audit', [AuditController::class, 'index'])
    ->middleware($rbacStack)
    ->defaults('roles', ['Admin', 'Auditor']);

/*
 |--------------------------------------------------------------------------
 | Evidence (Phase 4 — persisted + retrieval)
 |--------------------------------------------------------------------------
*/
Route::get('/evidence', [EvidenceController::class, 'index']);
Route::post('/evidence', [EvidenceController::class, 'store']);
Route::match(['GET','HEAD'], '/evidence/{id}', [EvidenceController::class, 'show']);

/*
 |--------------------------------------------------------------------------
 | Avatars scaffold (Phase 4)
 |--------------------------------------------------------------------------
*/
Route::post('/avatar', [AvatarController::class, 'store']);
