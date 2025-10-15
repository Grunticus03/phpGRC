<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\BrandProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Settings\UiSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class BrandProfilesApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.require_auth', true);
    }

    protected function tearDown(): void
    {
        config()->set('core.rbac.mode', 'stub');
        config()->set('core.rbac.require_auth', false);
        parent::tearDown();
    }

    public function test_index_returns_profiles(): void
    {
        $this->actingAsThemeAuditor();

        $response = $this->getJson('/settings/ui/brand-profiles');

        $response->assertOk();
        $response->assertJsonStructure([
            'ok',
            'profiles' => [
                [
                    'id',
                    'name',
                    'is_default',
                    'is_active',
                    'is_locked',
                    'brand' => [
                        'title_text',
                        'favicon_asset_id',
                        'primary_logo_asset_id',
                        'secondary_logo_asset_id',
                        'header_logo_asset_id',
                        'footer_logo_asset_id',
                        'footer_logo_disabled',
                    ],
                ],
            ],
        ]);
    }

    public function test_store_creates_profile(): void
    {
        $this->actingAsThemeManager();

        $response = $this->postJson('/settings/ui/brand-profiles', [
            'name' => 'Marketing Launch',
        ]);

        $response->assertCreated();
        $profileId = $response->json('profile.id');
        self::assertIsString($profileId);

        $this->assertDatabaseHas('brand_profiles', [
            'id' => $profileId,
            'name' => 'Marketing Launch',
        ]);
    }

    public function test_update_profile_allows_changes_for_non_default(): void
    {
        $user = $this->actingAsThemeManager();

        /** @var UiSettingsService $settings */
        $settings = app(UiSettingsService::class);
        $profile = $settings->createBrandProfile('Campaign Profile');

        $response = $this->putJson('/settings/ui/brand-profiles/'.$profile->getAttribute('id'), [
            'name' => 'Campaign Updated',
            'brand' => [
                'title_text' => 'New Title',
                'footer_logo_disabled' => true,
            ],
        ]);

        $response->assertOk();
        $response->assertJsonPath('profile.name', 'Campaign Updated');

        $this->assertDatabaseHas('brand_profiles', [
            'id' => $profile->getAttribute('id'),
            'name' => 'Campaign Updated',
            'footer_logo_disabled' => true,
        ]);
    }

    public function test_update_profile_rejects_default(): void
    {
        $this->actingAsThemeManager();

        /** @var UiSettingsService $settings */
        $settings = app(UiSettingsService::class);
        $default = $settings->brandProfileById('bp_default');
        self::assertInstanceOf(BrandProfile::class, $default);

        $response = $this->putJson('/settings/ui/brand-profiles/'.$default->getAttribute('id'), [
            'brand' => [
                'title_text' => 'Unauthorized',
            ],
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'ok' => false,
            'code' => 'PROFILE_LOCKED',
        ]);
    }

    public function test_activate_profile_sets_active_flag(): void
    {
        $this->actingAsThemeManager();

        /** @var UiSettingsService $settings */
        $settings = app(UiSettingsService::class);
        $profile = $settings->createBrandProfile('Night Mode');

        $response = $this->postJson('/settings/ui/brand-profiles/'.$profile->getAttribute('id').'/activate');

        $response->assertOk();

        $this->assertTrue($profile->fresh()->getAttribute('is_active'));
        $this->assertFalse(
            BrandProfile::query()
                ->where('id', '!=', $profile->getAttribute('id'))
                ->where('is_active', true)
                ->exists()
        );
    }

    public function test_delete_profile_removes_record(): void
    {
        $this->actingAsThemeManager();

        /** @var UiSettingsService $settings */
        $settings = app(UiSettingsService::class);
        $profile = $settings->createBrandProfile('Disposable Profile');
        $profileId = (string) $profile->getAttribute('id');

        $response = $this->deleteJson('/settings/ui/brand-profiles/'.$profileId);

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'deleted' => [
                'id' => $profileId,
            ],
        ]);

        $this->assertDatabaseMissing('brand_profiles', [
            'id' => $profileId,
        ]);
    }

    public function test_delete_profile_rejects_default(): void
    {
        $this->actingAsThemeManager();

        /** @var UiSettingsService $settings */
        $settings = app(UiSettingsService::class);
        $default = $settings->brandProfileById('bp_default');
        self::assertInstanceOf(BrandProfile::class, $default);

        $response = $this->deleteJson('/settings/ui/brand-profiles/'.$default->getAttribute('id'));

        $response->assertStatus(409);
        $response->assertJson([
            'ok' => false,
            'code' => 'PROFILE_LOCKED',
        ]);
    }

    private function actingAsThemeManager(): User
    {
        $user = User::factory()->create();
        $this->assignRole($user, 'role_theme_manager', 'Theme Manager');
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingAsThemeAuditor(): User
    {
        $user = User::factory()->create();
        $this->assignRole($user, 'role_theme_auditor', 'Theme Auditor');
        Sanctum::actingAs($user);

        return $user;
    }

    private function assignRole(User $user, string $roleId, string $roleName): void
    {
        Role::query()->updateOrCreate(['id' => $roleId], ['name' => $roleName]);
        $user->roles()->sync([$roleId]);
    }
}
