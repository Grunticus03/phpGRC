<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Services\Settings\UiSettingsService;
use Illuminate\Http\JsonResponse;

final class UiPublicSettingsController extends Controller
{
    public function __invoke(UiSettingsService $service): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'config' => $service->publicConfig(),
        ]);
    }
}
