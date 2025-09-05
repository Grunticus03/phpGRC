# @phpgrc:/api/routes/api.php
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
use App\Http\Middleware\BreakGlassGuard;
use Illuminate\Support\Facades\Route;

/*
 |----------------------------------------------------------------------
 | Reserved setup paths (Phase 1 CORE-001 — stubs only, no handlers yet)
 |----------------------------------------------------------------------
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
 |----------------------------------------------------------------------
 | Health
 |----------------------------------------------------------------------
*/
Route::get('/health', fn () => response()->json(['ok' => true]));

/*
 |----------------------------------------------------------------------
 | Auth placeholders (Phase 2 scaffolding)
 |  - Controllers are NOT invokable; route to explicit methods.
 |----------------------------------------------------------------------
*/
Route::post('/auth/login',  [LoginController::class,  'login']);
Route::post('/auth/logout', [LogoutController::class, 'logout']);
Route::get('/auth/me',      [MeController::class,     'me']);

Route::post('/auth/totp/enroll', [TotpController::class, 'enroll']);
Route::post('/auth/totp/verify', [TotpController::class, 'verify']);

/*
 |----------------------------------------------------------------------
 | Break-glass (disabled by default)
 |  - Guard returns 404 unless config('core.auth.break_glass.enabled') is true.
 |----------------------------------------------------------------------
*/
Route::post('/auth/break-glass', [BreakGlassController::class, 'invoke'])
    ->middleware(BreakGlassGuard::class);

/*
 |----------------------------------------------------------------------
 | Admin Settings framework (skeleton only, no DB I/O)
 |----------------------------------------------------------------------
*/
Route::prefix('/admin')->group(function (): void {
    Route::get('/settings', [SettingsController::class, 'index']);
    Route::put('/settings', [SettingsController::class, 'update']);
});

/*
 |----------------------------------------------------------------------
 | Exports stubs (Phase 2 placeholders; real delivery in Phase 4)
 |----------------------------------------------------------------------
*/
Route::prefix('/exports')->group(function (): void {
    Route::post('/', [ExportController::class, 'create']);                 // POST /api/exports
    Route::get('/{jobId}/status', [StatusController::class, 'show']);      // GET  /api/exports/{jobId}/status
    Route::get('/{jobId}/download', [ExportController::class, 'download']); // GET  /api/exports/{jobId}/download
});
