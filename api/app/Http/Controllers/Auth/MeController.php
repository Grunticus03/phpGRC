<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class MeController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['user' => ['id' => 0, 'email' => 'placeholder@example.com', 'roles' => []]]);
    }
}
