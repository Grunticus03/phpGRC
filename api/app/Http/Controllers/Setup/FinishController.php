<?php
declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Finalize setup. Validates coarse prerequisites via /setup/status logic.
 */
final class FinishController extends Controller
{
    public function finish(SetupStatusController $status): JsonResponse
    {
        $res = $status->show();
        /** @var array<string,mixed> $payload */
        $payload = $res->getData(true) ?: [];

        if (($payload['setupComplete'] ?? false) !== true) {
            return response()->json(['ok' => false, 'code' => 'SETUP_ALREADY_COMPLETED'] + $payload, 409);
        }

        // In future: flip DB flag core.setup.complete=true. For now, status-complete implies done.
        return response()->json(['ok' => true], 200);
    }
}

