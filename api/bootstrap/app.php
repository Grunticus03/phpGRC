<?php

declare(strict_types=1);

use App\Providers\AuthServiceProvider;
use App\Providers\EventServiceProvider;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // avoid guest redirects to non-existent 'login'
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->redirectUsersTo(fn () => null);
    })
    ->withProviders([
        AuthServiceProvider::class,
        EventServiceProvider::class,
    ])
    ->withExceptions(function (Exceptions $exceptions): void {
        // return JSON 401 instead of redirecting to 'login'
        $exceptions->render(function (AuthenticationException $e, Request $request) {
            return response()->json([
                'ok'      => false,
                'code'    => 'UNAUTHENTICATED',
                'message' => 'Authentication required.',
            ], 401);
        });
    })
    ->create();
