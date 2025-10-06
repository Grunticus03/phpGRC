<?php

declare(strict_types=1);

namespace Tests\Unit\Rbac;

use App\Http\Controllers\Rbac\UserSearchController;
use App\Services\Settings\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class UserSearchDefaultPerPageTest extends TestCase
{
    private function makeController(): UserSearchController
    {
        return new UserSearchController;
    }

    private function makeSettingsService(): SettingsService
    {
        return new SettingsService;
    }

    private function stubSchemaNoTables(): void
    {
        // SettingsService checks core_settings; controller checks users.
        Schema::shouldReceive('hasTable')->with('core_settings')->zeroOrMoreTimes()->andReturn(false);
        Schema::shouldReceive('hasTable')->with('users')->zeroOrMoreTimes()->andReturn(false);
    }

    private function callIndex(array $query, ?int $cfgDefaultPerPage = null): array
    {
        $this->stubSchemaNoTables();

        if ($cfgDefaultPerPage !== null) {
            config(['core.rbac.user_search.default_per_page' => $cfgDefaultPerPage]);
        }

        $request = Request::create('/api/rbac/users/search', 'GET', $query);
        $controller = $this->makeController();
        $settings = $this->makeSettingsService();

        $resp = $controller->index($request, $settings);
        $this->assertSame(200, $resp->getStatusCode(), 'Expected HTTP 200 for stub path without users table');

        /** @var array{ok:bool,data:array,meta:array{page:int,per_page:int,total:int,total_pages:int}} $json */
        $json = $resp->getData(true);

        return $json;
    }

    public function test_default_per_page_uses_settings_when_omitted(): void
    {
        $json = $this->callIndex(['q' => 'alpha'], 200);

        $this->assertTrue($json['ok']);
        $this->assertSame(1, $json['meta']['page']);
        $this->assertSame(200, $json['meta']['per_page'], 'Expected per_page to come from settings when not provided');
        $this->assertSame(0, $json['meta']['total']);
        $this->assertSame(0, $json['meta']['total_pages']);
    }

    /**
     * @return iterable<string, array{cfg:int, expected:int}>
     */
    public static function settingsDefaultClampProvider(): iterable
    {
        yield 'below_minimum' => ['cfg' => 0, 'expected' => 1];
        yield 'at_minimum' => ['cfg' => 1, 'expected' => 1];
        yield 'normal' => ['cfg' => 50, 'expected' => 50];
        yield 'upper_ok' => ['cfg' => 500, 'expected' => 500];
        yield 'above_max' => ['cfg' => 9999, 'expected' => 500];
    }

    #[DataProvider('settingsDefaultClampProvider')]
    public function test_settings_default_is_clamped_to_bounds(int $cfg, int $expected): void
    {
        $json = $this->callIndex(['q' => 'alpha'], $cfg);

        $this->assertTrue($json['ok']);
        $this->assertSame($expected, $json['meta']['per_page']);
    }

    /**
     * @return iterable<string, array{input:string, expected:int}>
     */
    public static function queryPerPageClampProvider(): iterable
    {
        yield 'below_minimum' => ['input' => '0',    'expected' => 1];
        yield 'minimum' => ['input' => '1',    'expected' => 1];
        yield 'normal' => ['input' => '50',   'expected' => 50];
        yield 'upper_ok' => ['input' => '500',  'expected' => 500];
        yield 'above_max' => ['input' => '9999', 'expected' => 500];
        // Negative value strings are ignored by parser (not digits), so default applies (50 here).
        yield 'negative_ignored_defaults' => ['input' => '-5',  'expected' => 50];
        // Non-numeric is ignored by parser, so default applies (50 here).
        yield 'nonnumeric_ignored_defaults' => ['input' => 'abc', 'expected' => 50];
    }

    #[DataProvider('queryPerPageClampProvider')]
    public function test_query_per_page_is_clamped_and_overrides_settings(string $input, int $expected): void
    {
        // Set default=50 so when input is ignored, clamp expectation is 50.
        $json = $this->callIndex(['q' => 'alpha', 'per_page' => $input], 50);

        $this->assertTrue($json['ok']);
        $this->assertSame($expected, $json['meta']['per_page']);
    }

    /**
     * @return iterable<string, array{input:string, expected:int}>
     */
    public static function pageClampProvider(): iterable
    {
        yield 'below_minimum' => ['input' => '0',   'expected' => 1];
        yield 'minimum' => ['input' => '1',   'expected' => 1];
        yield 'large' => ['input' => '999', 'expected' => 999];
        // Negative and non-numeric fall back to default (1), due to digits-only parse.
        yield 'negative_ignored_defaults' => ['input' => '-2', 'expected' => 1];
        yield 'nonnumeric_ignored_default' => ['input' => 'x',  'expected' => 1];
    }

    #[DataProvider('pageClampProvider')]
    public function test_page_is_clamped_minimum_and_parsed_digits_only(string $input, int $expected): void
    {
        $json = $this->callIndex(['q' => 'alpha', 'page' => $input], 50);

        $this->assertTrue($json['ok']);
        $this->assertSame($expected, $json['meta']['page']);
    }
}
