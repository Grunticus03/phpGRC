<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class UiThemeManifestController extends Controller
{
    public function __invoke(): JsonResponse
    {
        /** @var array<string,mixed> $manifest */
        $manifest = (array) config('ui.manifest', []);

        return response()->json($manifest, 200, [
            'Cache-Control' => 'no-cache, max-age=0',
            'Pragma' => 'no-cache',
        ]);
    }
}
