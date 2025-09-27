<?php

declare(strict_types=1);

namespace Tests\Unit\Rbac;

use App\Http\Controllers\Rbac\UserSearchController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class UserSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUsers(int $n = 30, string $prefix = 'User'): void
    {
        for ($i = 1; $i <= $n; $i++) {
            User::query()->create([
                'name'     => sprintf('%s %02d', $prefix, $i),
                'email'    => sprintf('%s%02d@example.test', strtolower($prefix), $i),
                'password' => bcrypt('secret'),
            ]);
        }
    }

    public function test_default_limit_and_shape(): void
    {
        $this->makeUsers(30, 'Alpha');

        $req = Request::create('/rbac/users/search', 'GET', ['q' => 'alpha']); // matches all
        /** @var \Illuminate\Http\JsonResponse $jsonResponse */
        $jsonResponse = app(UserSearchController::class)->index($req);

        $res = TestResponse::fromBaseResponse($jsonResponse);
        $res->assertStatus(200);

        $json = $res->json();

        $this->assertTrue($json['ok'] ?? false);
        $this->assertIsArray($json['data'] ?? null);
        $this->assertCount(20, $json['data']); // default limit=20

        $first = $json['data'][0] ?? [];
        $this->assertIsArray($first);
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('email', $first);
        $this->assertIsInt($first['id']);
        $this->assertIsString($first['name']);
        $this->assertIsString($first['email']);
    }

    public function test_limit_parsing_variants_and_clamping(): void
    {
        $this->makeUsers(150, 'Bravo');

        // numeric string
        $req1 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'limit' => '7']);
        $res1 = app(UserSearchController::class)->index($req1);
        $this->assertCount(7, ($res1->getData(true)['data'] ?? []));

        // array value → first numeric element
        $req2 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'limit' => ['5', '9']]);
        $res2 = app(UserSearchController::class)->index($req2);
        $this->assertCount(5, ($res2->getData(true)['data'] ?? []));

        // invalid → fallback default 20
        $req3 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'limit' => 'abc']);
        $res3 = app(UserSearchController::class)->index($req3);
        $this->assertCount(20, ($res3->getData(true)['data'] ?? []));

        // lower bound clamp (0 → 1)
        $req4 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'limit' => '0']);
        $res4 = app(UserSearchController::class)->index($req4);
        $this->assertCount(1, ($res4->getData(true)['data'] ?? []));

        // upper bound clamp (>100 → 100)
        $req5 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'limit' => '1000']);
        $res5 = app(UserSearchController::class)->index($req5);
        $this->assertCount(100, ($res5->getData(true)['data'] ?? []));
    }

    public function test_query_filters_by_name_or_email_case_insensitive(): void
    {
        // Distinct sets to prove filtering
        $this->makeUsers(5, 'Charlie');
        $this->makeUsers(5, 'Delta');

        // name match
        $req1 = Request::create('/rbac/users/search', 'GET', ['q' => 'charl']);
        $names1 = array_map(
            fn ($r) => $r['name'],
            (app(UserSearchController::class)->index($req1)->getData(true)['data'] ?? [])
        );
        $this->assertNotEmpty($names1);
        $this->assertTrue(collect($names1)->every(fn ($n) => stripos($n, 'charl') !== false));

        // email match
        $req2 = Request::create('/rbac/users/search', 'GET', ['q' => 'delta0']); // matches delta01..delta05 emails
        $emails2 = array_map(
            fn ($r) => $r['email'],
            (app(UserSearchController::class)->index($req2)->getData(true)['data'] ?? [])
        );
        $this->assertNotEmpty($emails2);
        $this->assertTrue(collect($emails2)->every(fn ($e) => stripos($e, 'delta0') !== false));
    }
}
