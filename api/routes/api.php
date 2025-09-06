<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\SettingsController;
use App\Http\Controllers\Auth\BreakGlassController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\TotpController;
use App\Http\Controllers\Export\ExportController;
use App\Http\Controllers\Export\StatusController;
use App\Http\Controllers\Rbac\RolesController;
use App\Http\Controllers\Audit\AuditController;
use App\Http\Controllers\Evidence\EvidenceController;
use App\Http\Controllers\Avatar\AvatarController;
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
 |  - Controllers are NOT invokable; route to explicit methods.
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
 |  - Guard returns 404 unless config('core.auth.break_glass.enabled') is true.
 |--------------------------------------------------------------------------
*/
Route::post('/auth/break-glass', [BreakGlassController::class, 'invoke'])
    ->middleware(BreakGlassGuard::class);

/*
 |--------------------------------------------------------------------------
 | Admin Settings framework (skeleton only, no DB I/O)
 |  - Spec uses POST. Keep PUT for backward compatibility.
 |--------------------------------------------------------------------------
*/
Route::prefix('/admin')
    ->middleware(RbacMiddleware::class) // Phase 4: no-op if core.rbac.enabled=false
    ->group(function (): void {
        Route::get('/settings', [SettingsController::class, 'index']);
        Route::post('/settings', [SettingsController::class, 'update']); // spec-preferred
        Route::put('/settings', [SettingsController::class, 'update']);  // legacy alias
    });

/*
 |--------------------------------------------------------------------------
 | Exports stubs (Phase 4 delivery)
 |  - Spec: POST /exports/{type}. Keep legacy POST /exports for compatibility.
 |--------------------------------------------------------------------------
*/
Route::prefix('/exports')->group(function (): void {
    Route::post('/{type}', [ExportController::class, 'createType']);        // spec route
    Route::post('/',       [ExportController::class, 'create']);            // legacy body route
    Route::get('/{jobId}/status',   [StatusController::class, 'show']);
    Route::get('/{jobId}/download', [ExportController::class, 'download']);
});

/*
 |--------------------------------------------------------------------------
 | RBAC roles scaffold (Phase 4 — stub-only)
 |--------------------------------------------------------------------------
*/
Route::prefix('/rbac')
    ->middleware(RbacMiddleware::class) // Phase 4: tag-only, no enforcement
    ->group(function (): void {
        Route::get('/roles', [RolesController::class, 'index']);
        Route::post('/roles', [RolesController::class, 'store']);
    });

/*
 |--------------------------------------------------------------------------
 | Audit trail scaffold (Phase 4 — read-only stub)
 |--------------------------------------------------------------------------
*/
Route::get('/audit', [AuditController::class, 'index']);

/*
 |--------------------------------------------------------------------------
 | Evidence scaffold (Phase 4 — upload no-op)
 |--------------------------------------------------------------------------
*/
Route::post('/evidence', [EvidenceController::class, 'store']);

/*
 |--------------------------------------------------------------------------
 | Avatars scaffold (Phase 4 — upload no-op)
 |--------------------------------------------------------------------------
*/
Route::post('/avatar', [AvatarController::class, 'store']);
