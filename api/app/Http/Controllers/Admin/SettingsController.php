<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Services\Settings\SettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SettingsController extends Controller
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    public function index(): JsonResponse
    {
        // Use the public API; it already merges defaults + DB overrides
        // and filters to the contract keys.
        $effective = $this->settings->effectiveConfig(); // ['core' => [...]]

        return response()->json([
            'ok'     => true,
            'config' => ['core' => $effective['core']],
        ], 200);
    }

    public function update(Request $request): JsonResponse
    {
        $payload  = $request->all();
        $isLegacy = Arr::has($payload, 'core') && is_array($payload['core']);
        $sections = $isLegacy
            ? (array) $payload['core']
            : Arr::only($payload, ['rbac', 'audit', 'evidence', 'avatars']);

        $allowedMime = (array) config('core.evidence.allowed_mime', [
            'application/pdf', 'image/png', 'image/jpeg', 'text/plain',
        ]);

        $rules = [
            'rbac'                 => ['sometimes', 'array'],
            'rbac.enabled'         => ['sometimes', 'boolean'],
            'rbac.roles'           => [
                'sometimes',
                'array',
                'min:1',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!is_array($value)) {
                        $fail('The roles must be an array.');
                        return;
                    }
                    foreach ($value as $role) {
                        if (!is_string($role) || $role === '' || mb_strlen($role) > 64) {
                            $fail('Each role name must be a non-empty string up to 64 characters.');
                            break;
                        }
                    }
                },
            ],

            'audit'                => ['sometimes', 'array'],
            'audit.enabled'        => ['sometimes', 'boolean'],
            'audit.retention_days' => ['sometimes', 'integer', 'min:1', 'max:730'],

            'evidence'                 => ['sometimes', 'array'],
            'evidence.enabled'         => ['sometimes', 'boolean'],
            'evidence.max_mb'          => ['sometimes', 'integer', 'min:1'],
            'evidence.allowed_mime'    => [
                'sometimes',
                'array',
                'min:1',
                function (string $attribute, mixed $value, \Closure $fail) use ($allowedMime): void {
                    if (!is_array($value)) {
                        $fail('The allowed_mime must be an array.');
                        return;
                    }
                    foreach ($value as $mime) {
                        if (!in_array($mime, $allowedMime, true)) {
                            $fail('One or more MIME types are not allowed.');
                            break;
                        }
                    }
                },
            ],

            'avatars'         => ['sometimes', 'array'],
            'avatars.enabled' => ['sometimes', 'boolean'],
            'avatars.size_px' => ['sometimes', 'integer', 'in:128'],
            'avatars.format'  => ['sometimes', Rule::in(['webp'])],
        ];

        $v = Validator::make($sections, $rules);

        if ($v->fails()) {
            $errors = $this->nestErrors($v->errors()->toArray());

            if ($isLegacy) {
                return response()->json(['errors' => $errors], 422);
            }

            return response()->json([
                'ok'     => false,
                'code'   => 'VALIDATION_FAILED',
                'errors' => $errors,
            ], 422);
        }

        $accepted = $v->validated();

        // Robust boolean coercion: accepts bools and strings like "false"/"0".
        $raw      = config('core.settings.stub_only', true);
        $coerced  = filter_var($raw, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
        $stubOnly = is_bool($coerced) ? $coerced : (bool) $raw;

        if ($stubOnly) {
            return response()->json([
                'ok'       => true,
                'applied'  => false,
                'note'     => 'stub-only',
                'accepted' => $accepted,
            ], 200);
        }

        $actorId = $request->user()?->id ? (int) $request->user()->id : null;
        $context = [
            'ip'         => $request->ip(),
            'user_agent' => (string) $request->userAgent(),
        ];

        $result = $this->settings->apply($accepted, $actorId, $context);

        return response()->json([
            'ok'       => true,
            'applied'  => true,
            'accepted' => $accepted,
            'changes'  => $result['changes'],
        ], 200);
    }

    /**
     * @param array<string, array<int, string>> $flat
     * @return array<string, mixed>
     */
    private function nestErrors(array $flat): array
    {
        $nested = [];
        foreach ($flat as $key => $messages) {
            Arr::set($nested, $key, array_values($messages));
        }
        return $nested;
    }
}
