<?php

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Requests\Settings\BrandProfileActivateRequest;
use App\Http\Requests\Settings\BrandProfileStoreRequest;
use App\Http\Requests\Settings\BrandProfileUpdateRequest;
use App\Models\BrandProfile;
use App\Services\Settings\Exceptions\BrandProfileLockedException;
use App\Services\Settings\UiSettingsService;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class BrandProfilesController extends Controller
{
    public function __construct(private readonly UiSettingsService $settings) {}

    public function index(Request $request): JsonResponse
    {
        $profiles = $this->settings->brandProfiles();
        $data = $profiles
            ->toBase()
            ->map(fn (BrandProfile $profile): array => $this->serializeProfile($profile))
            ->all();

        return response()->json([
            'ok' => true,
            'profiles' => $data,
        ], 200);
    }

    public function store(BrandProfileStoreRequest $request): JsonResponse
    {
        /** @var string $name */
        $name = $request->input('name');
        /** @var array<string,mixed> $brand */
        $brand = is_array($request->input('brand')) ? (array) $request->input('brand') : [];

        $source = null;
        /** @var mixed $sourceId */
        $sourceId = $request->input('source_profile_id');
        if (is_string($sourceId) && $sourceId !== '') {
            $source = $this->settings->brandProfileById($sourceId);
            if (! $source instanceof BrandProfile) {
                return response()->json([
                    'ok' => false,
                    'code' => 'PROFILE_NOT_FOUND',
                    'message' => 'Source branding profile not found.',
                ], 404);
            }
        }

        $profile = $this->settings->createBrandProfile($name, $brand, $source);

        return response()->json([
            'ok' => true,
            'profile' => $this->serializeProfile($profile),
        ], 201);
    }

    public function update(BrandProfileUpdateRequest $request, string $profileId): JsonResponse
    {
        $profile = $this->settings->brandProfileById($profileId);
        if (! $profile instanceof BrandProfile) {
            return response()->json([
                'ok' => false,
                'code' => 'PROFILE_NOT_FOUND',
                'message' => 'Branding profile not found.',
            ], 404);
        }

        if ($profile->getAttribute('is_default') || $profile->getAttribute('is_locked')) {
            return response()->json([
                'ok' => false,
                'code' => 'PROFILE_LOCKED',
                'message' => 'Default branding profile cannot be modified.',
            ], 409);
        }

        /** @var array<string,mixed> $payload */
        $payload = is_array($request->input('brand')) ? $request->input('brand') : [];
        if ($request->filled('name')) {
            $payload['name'] = $request->string('name')->trim()->toString();
        }

        try {
            $updated = $this->settings->updateBrandProfile($profile, $payload);
        } catch (BrandProfileLockedException $exception) {
            return response()->json([
                'ok' => false,
                'code' => 'PROFILE_LOCKED',
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'profile' => $this->serializeProfile($updated),
        ], 200);
    }

    public function activate(BrandProfileActivateRequest $request, string $profileId): JsonResponse
    {
        $profile = $this->settings->brandProfileById($profileId);
        if (! $profile instanceof BrandProfile) {
            return response()->json([
                'ok' => false,
                'code' => 'PROFILE_NOT_FOUND',
                'message' => 'Branding profile not found.',
            ], 404);
        }

        $this->settings->activateBrandProfile($profile);

        return response()->json([
            'ok' => true,
            'profile' => $this->serializeProfile($profile->refresh()),
        ], 200);
    }

    public function destroy(Request $request, string $profileId): JsonResponse
    {
        $profile = $this->settings->brandProfileById($profileId);
        if (! $profile instanceof BrandProfile) {
            return response()->json([
                'ok' => false,
                'code' => 'PROFILE_NOT_FOUND',
                'message' => 'Branding profile not found.',
            ], 404);
        }

        $wasActive = (bool) $profile->getAttribute('is_active');

        try {
            $this->settings->deleteBrandProfile($profile);
        } catch (BrandProfileLockedException $exception) {
            return response()->json([
                'ok' => false,
                'code' => 'PROFILE_LOCKED',
                'message' => $exception->getMessage(),
            ], 409);
        }

        return response()->json([
            'ok' => true,
            'deleted' => [
                'id' => $profileId,
                'was_active' => $wasActive,
            ],
        ], 200);
    }

    /**
     * @return array<string,mixed>
     */
    private function serializeProfile(BrandProfile $profile): array
    {
        $brand = $this->settings->brandProfileAsConfig($profile);

        /** @var CarbonInterface|null $created */
        $created = $profile->getAttribute('created_at');
        /** @var CarbonInterface|null $updated */
        $updated = $profile->getAttribute('updated_at');

        return [
            'id' => $profile->getAttribute('id'),
            'name' => $profile->getAttribute('name'),
            'is_default' => (bool) $profile->getAttribute('is_default'),
            'is_active' => (bool) $profile->getAttribute('is_active'),
            'is_locked' => (bool) $profile->getAttribute('is_locked'),
            'brand' => $brand,
            'created_at' => $created instanceof CarbonInterface ? $created->toJSON() : null,
            'updated_at' => $updated instanceof CarbonInterface ? $updated->toJSON() : null,
        ];
    }
}
