<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\IdpProviderHealthPreviewRequest;
use App\Http\Requests\Auth\LdapDirectoryBrowseRequest;
use App\Services\Auth\IdpProviderToolService;
use App\Services\Auth\Ldap\LdapException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class IdpProviderToolsController extends Controller
{
    public function __construct(private readonly IdpProviderToolService $tools) {}

    public function previewHealth(IdpProviderHealthPreviewRequest $request): JsonResponse
    {
        /** @var array<string,mixed> $payload */
        $payload = $request->validated();

        try {
            $result = $this->tools->previewHealth($payload);
        } catch (ValidationException $e) {
            throw $e;
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentResponse($e->getMessage());
        }

        return response()->json([
            'ok' => $result->status === IdpHealthCheckResult::STATUS_OK,
            'status' => $result->status,
            'message' => $result->message,
            'checked_at' => $result->checkedAt->toIso8601String(),
            'details' => $result->details,
        ], 200);
    }

    /**
     * @SuppressWarnings("PMD.StaticAccess")
     */
    public function browseLdap(LdapDirectoryBrowseRequest $request): JsonResponse
    {
        /** @var array<string,mixed> $payload */
        $payload = $request->validated();

        /** @var mixed $baseDnRaw */
        $baseDnRaw = $payload['base_dn'] ?? null;
        $baseDn = is_string($baseDnRaw) && trim($baseDnRaw) !== '' ? trim($baseDnRaw) : null;

        unset($payload['base_dn']);

        try {
            $result = $this->tools->browseLdap($payload, $baseDn);
        } catch (ValidationException $e) {
            throw $e;
        } catch (LdapException $e) {
            throw ValidationException::withMessages([
                'config' => [$e->getMessage()],
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->invalidArgumentResponse($e->getMessage());
        }

        return response()->json([
            'ok' => true,
            'root' => (bool) ($result['root'] ?? false),
            'base_dn' => $result['base_dn'] ?? $baseDn,
            'entries' => $result['entries'] ?? [],
        ], 200);
    }

    private function invalidArgumentResponse(string $message): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => 'IDP_PROVIDER_INVALID',
            'message' => $message,
        ], 422);
    }
}
