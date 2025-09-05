# @phpgrc:/api/routes/api.php
# Purpose: Define placeholder auth routes and health endpoint
<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\BreakGlassController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\MeController;
use App\Http\Controllers\Auth\TotpController;
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
 |----------------------------------------------------------------------
*/
Route::post('/auth/login',  LoginController::class);
Route::post('/auth/logout', LogoutController::class);
Route::get('/auth/me',      MeController::class);

Route::post('/auth/totp/enroll', [TotpController::class, 'enroll']);
Route::post('/auth/totp/verify', [TotpController::class, 'verify']);

Route::post('/auth/break-glass', BreakGlassController::class)
    ->middleware('breakglass.guard');
