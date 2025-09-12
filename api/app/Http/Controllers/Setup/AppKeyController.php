<?php
declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use Illuminate\Contracts\Console\Kernel as ArtisanKernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;

/**
 * Generate Laravel APP_KEY when allowed. Otherwise stub 202.
 */
final class AppKeyController extends Controller
{
    public function generate(ArtisanKernel $artisan): JsonResponse
    {
        if (!Config::get('core.setup.enabled', true)) {
            return response()->json(['ok' => false, 'code' => 'SETUP_STEP_DISABLED'], 400);
        }

        if ((string) config('app.key', '') !== '') {
            return response()->json(['ok' => false, 'code' => 'APP_KEY_EXISTS'], 409);
        }

        if (!Config::get('core.setup.allow_commands', false)) {
            return response()->json(['ok' => true, 'note' => 'stub-only'], 202);
        }

        $artisan->call('key:generate', ['--force' => true]);
        return response()->json(['ok' => true], 200);
    }
}

