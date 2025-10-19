<?php

declare(strict_types=1);

use App\Providers\AuthServiceProvider;
use App\Providers\ConfigOverlayServiceProvider;
use App\Providers\EventServiceProvider;
use App\Providers\IdpServiceProvider;
use App\Providers\SettingsServiceProvider;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '' // keep API at root, no automatic /api prefix
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Redirect browser (HTML) traffic to SPA login. Keep APIs JSON-only.
        $middleware->redirectGuestsTo(function (Request $request) {
            if ($request->expectsJson()) {
                return null; // APIs: no redirect
            }
            // Treat these as API-style even if Accept is text/html
            $path = ltrim($request->path(), '/');
            $apiish = [
                'rbac', 'admin', 'exports', 'audit', 'evidence',
                'avatar', 'health', 'openapi', 'docs',
            ];
            foreach ($apiish as $pfx) {
                if (str_starts_with($path, $pfx)) {
                    return null;
                }
            }

            return '/login';
        });

        // Do not force any redirect for already-authenticated users.
        $middleware->redirectUsersTo(fn () => null);
    })
    ->withProviders([
        // Load overlay before gates read config.
        ConfigOverlayServiceProvider::class,
        SettingsServiceProvider::class, // DB-backed settings at boot
        IdpServiceProvider::class,
        AuthServiceProvider::class,
        EventServiceProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        // APIs: JSON 401; Web: allow framework default (redirectGuestsTo) to run.
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->expectsJson()) {
                return response()->json([
                    'ok' => false,
                    'code' => 'UNAUTHENTICATED',
                    'message' => 'Authentication required.',
                ], 401);
            }

            return null;
        });
    })
    ->create();
