<?php
declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use App\Http\Requests\Setup\DbConfigRequest;
use App\Services\Setup\ConfigFileWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Config;
use PDO;
use Throwable;

/**
 * DB test + atomic write of installer DB config.
 */
final class DbController extends Controller
{
    public function test(DbConfigRequest $request): JsonResponse
    {
        if (!Config::get('core.setup.enabled', true)) {
            return response()->json(['ok' => false, 'code' => 'SETUP_STEP_DISABLED'], 400);
        }

        /** @var array{driver:string,host:string,port?:int,database:string,charset?:string,username:string,password?:string} $cfg */
        $cfg = $request->validated();
        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            strtolower($cfg['driver']),
            $cfg['host'],
            $cfg['port'] ?? 3306,
            $cfg['database'],
            strtolower($cfg['charset'] ?? 'utf8mb4')
        );

        try {
            $pdo = new PDO($dsn, $cfg['username'], $cfg['password'] ?? '', [
                PDO::ATTR_TIMEOUT            => 3,
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $pdo->query('SELECT 1');
        } catch (Throwable $e) {
            return response()->json([
                'ok'    => false,
                'code'  => 'DB_CONFIG_INVALID',
                'error' => 'Connection failed: ' . $e->getMessage(),
            ], 422);
        }

        return response()->json(['ok' => true], 200);
    }

    public function write(DbConfigRequest $request, ConfigFileWriter $writer): JsonResponse
    {
        if (!Config::get('core.setup.enabled', true)) {
            return response()->json(['ok' => false, 'code' => 'SETUP_STEP_DISABLED'], 400);
        }

        /** @var array<string,mixed> $cfg */
        $cfg = $request->validated();
        try {
            $path = $writer->writeAtomic($cfg, (string) Config::get('core.setup.shared_config_path', '/opt/phpgrc/shared/config.php'));
            return response()->json(['ok' => true, 'path' => $path], 200);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'code' => 'DB_WRITE_FAILED', 'error' => $e->getMessage()], 500);
        }
    }
}

