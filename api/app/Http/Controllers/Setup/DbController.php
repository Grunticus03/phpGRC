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

        /** @var array{driver:string,host:string,port?:int|string,database:string,charset?:string,username:string,password?:string} $cfg */
        $cfg = $request->validated();

        $portRaw = $cfg['port'] ?? null;
        $port = is_int($portRaw)
            ? $portRaw
            : (is_string($portRaw) && ctype_digit($portRaw) ? (int) $portRaw : 3306);

        $driver  = strtolower($cfg['driver']);
        $host    = $cfg['host'];
        $dbName  = $cfg['database'];
        $charset = strtolower($cfg['charset'] ?? 'utf8mb4');

        $dsn = sprintf(
            '%s:host=%s;port=%d;dbname=%s;charset=%s',
            $driver,
            $host,
            $port,
            $dbName,
            $charset
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

        $defaultPath = '/opt/phpgrc/shared/config.php';
        /** @var mixed $pathRaw */
        $pathRaw = Config::get('core.setup.shared_config_path', $defaultPath);
        $targetPath = is_string($pathRaw) && $pathRaw !== '' ? $pathRaw : $defaultPath;

        try {
            $path = $writer->writeAtomic($cfg, $targetPath);
            return response()->json(['ok' => true, 'path' => $path], 200);
        } catch (Throwable $e) {
            return response()->json(['ok' => false, 'code' => 'DB_WRITE_FAILED', 'error' => $e->getMessage()], 500);
        }
    }
}
