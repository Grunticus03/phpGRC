<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Services\Settings\ThemePackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class UiThemeManifestController extends Controller
{
    public function __construct(private readonly ThemePackService $themePacks) {}

    public function __invoke(): JsonResponse
    {
        $manifest = $this->themePacks->manifest();

        return response()->json($manifest, 200, [
            'Cache-Control' => 'no-cache, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
