<?php

declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;

/**
 * Setup status endpoint.
 * Computes coarse checks from environment and DB, then picks nextStep per prereqs.
 */
final class SetupStatusController extends Controller
{
    public function show(): JsonResponse
    {
        if (! Config::get('core.setup.enabled', true)) {
            return response()->json(['ok' => false, 'code' => 'SETUP_STEP_DISABLED'], 400);
        }

        $defaultPath = '/opt/phpgrc/shared/config.php';
        /** @var mixed $dbCfgPathRaw */
        $dbCfgPathRaw = Config::get('core.setup.shared_config_path', $defaultPath);
        $dbConfigPath = is_string($dbCfgPathRaw) && $dbCfgPathRaw !== '' ? $dbCfgPathRaw : $defaultPath;
        $dbConfigured = is_file($dbConfigPath);

        /** @var mixed $appKeyRaw */
        $appKeyRaw = config('app.key');
        $appKey = is_string($appKeyRaw) ? $appKeyRaw : '';
        $appKeyPresent = $appKey !== '';

        $schemaInit = false;
        try {
            $schemaInit = Schema::hasTable('migrations') && Schema::hasTable('users');
        } catch (\Throwable) {
            $schemaInit = false;
        }

        $adminSeeded = false;
        try {
            $adminSeeded = class_exists(User::class) && Schema::hasTable('users') && User::query()->exists();
        } catch (\Throwable) {
            $adminSeeded = false;
        }

        $adminMfaVerified = false; // Phase-2/4: tracked later in DB; stub false until implemented.

        $smtpConfigured = false;   // Phase-4: settings-backed later.
        $idpConfigured = false;   // Phase-6: external IdP later.
        $brandingDone = false;   // Phase-4: settings-backed later.

        $checks = [
            'db_config' => $dbConfigured,
            'app_key' => $appKeyPresent,
            'schema_init' => $schemaInit,
            'admin_seed' => $adminSeeded,
            'admin_mfa_verify' => $adminMfaVerified,
            'smtp' => $smtpConfigured,
            'idp' => $idpConfigured,
            'branding' => $brandingDone,
        ];

        $nextStep = $this->determineNextStep($checks);
        $setupComplete = $nextStep === null;

        return response()->json([
            'ok' => true,
            'setupComplete' => $setupComplete,
            'nextStep' => $nextStep,
            'checks' => $checks,
        ], 200);
    }

    /**
     * @param  array<string,bool>  $checks
     */
    private function determineNextStep(array $checks): ?string
    {
        // Prereqs graph (CORE-001). If any required step missing, return that step. Otherwise null.
        if (! $checks['db_config']) {
            return 'db_config';
        }
        if (! $checks['app_key']) {
            return 'app_key';
        }
        if (! $checks['schema_init']) {
            return 'schema_init';
        }
        if (! $checks['admin_seed']) {
            return 'admin_seed';
        }
        if (! $checks['admin_mfa_verify']) {
            return 'admin_mfa_verify';
        }

        // Optional: smtp, idp, branding can be done any time after schema.
        return null;
    }
}
