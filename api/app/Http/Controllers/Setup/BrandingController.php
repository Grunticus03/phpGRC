<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Http\Requests\Setup\BrandingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Branding payload validator + echo. Settings persistence defers to Settings module.
 */
final class BrandingController extends Controller
{
    public function store(BrandingRequest $request): JsonResponse
    {
        /** @var array<string,mixed> $data */
        $data = $request->validated();

        return response()->json(['ok' => true, 'branding' => $data], 200);
    }
}
