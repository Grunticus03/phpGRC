<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use Illuminate\Contracts\Console\Kernel as ArtisanKernel;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;

/**
 * Initialize DB schema by running migrations when allowed.
 */
final class SchemaController extends Controller
{
    public function init(ArtisanKernel $artisan): JsonResponse
    {
        if (! Config::get('core.setup.enabled', true)) {
            return response()->json(['ok' => false, 'code' => 'SETUP_STEP_DISABLED'], 400);
        }

        if (! Config::get('core.setup.allow_commands', false)) {
            return response()->json(['ok' => true, 'note' => 'stub-only'], 202);
        }

        try {
            $artisan->call('migrate', ['--force' => true]);

            return response()->json(['ok' => true], 200);
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'code' => 'SCHEMA_INIT_FAILED', 'error' => $e->getMessage()], 500);
        }
    }
}
