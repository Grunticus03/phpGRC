<?php

declare(strict_types=1);

namespace App\Http;

use App\Http\Middleware\AuthRequired;
use App\Http\Middleware\BreakGlassGuard;
use Illuminate\Foundation\Http\Kernel as HttpKernel;

final class Kernel extends HttpKernel
{
    protected $middlewareAliases = [
        'auth.required' => AuthRequired::class,
        'breakglass.guard' => BreakGlassGuard::class,
    ];
}
