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

    public function test_default_pagination_and_shape(): void
    {
        $this->makeUsers(80, 'Alpha');

        $req = Request::create('/rbac/users/search', 'GET', ['q' => 'alpha']); // matches all
        /** @var \Illuminate\Http\JsonResponse $jsonResponse */
        $jsonResponse = app(UserSearchController::class)->index($req);

        $res = TestResponse::fromBaseResponse($jsonResponse);
        $res->assertStatus(200);

        $json = $res->json();

        $this->assertTrue($json['ok'] ?? false);
        $this->assertIsArray($json['data'] ?? null);
        $this->assertCount(50, $json['data']); // default per_page=50

        $first = $json['data'][0] ?? [];
        $this->assertIsArray($first);
        $this->assertArrayHasKey('id', $first);
        $this->assertArrayHasKey('name', $first);
        $this->assertArrayHasKey('email', $first);
        $this->assertIsInt($first['id']);
        $this->assertIsString($first['name']);
        $this->assertIsString($first['email']);

        $meta = $json['meta'] ?? [];
        $this->assertSame(1, $meta['page'] ?? null);
        $this->assertSame(50, $meta['per_page'] ?? null);
        $this->assertSame(80, $meta['total'] ?? null);
        $this->assertSame(2, $meta['total_pages'] ?? null);
    }

    public function test_per_page_parsing_variants_and_clamping(): void
    {
        $this->makeUsers(600, 'Bravo');

        // numeric string
        $req1 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'per_page' => '7']);
        $res1 = app(UserSearchController::class)->index($req1);
        $this->assertCount(7, ($res1->getData(true)['data'] ?? []));

        // array value → first numeric element
        $req2 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'per_page' => ['5', '9']]);
        $res2 = app(UserSearchController::class)->index($req2);
        $this->assertCount(5, ($res2->getData(true)['data'] ?? []));

        // invalid → fallback default 50
        $req3 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'per_page' => 'abc']);
        $res3 = app(UserSearchController::class)->index($req3);
        $this->assertCount(50, ($res3->getData(true)['data'] ?? []));

        // lower bound clamp (0 → 1)
        $req4 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'per_page' => '0']);
        $res4 = app(UserSearchController::class)->index($req4);
        $this->assertCount(1, ($res4->getData(true)['data'] ?? []));

        // upper bound clamp (>500 → 500)
        $req5 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'per_page' => '1000']);
        $res5 = app(UserSearchController::class)->index($req5);
        $this->assertCount(500, ($res5->getData(true)['data'] ?? []));
    }

    public function test_page_offsetting_and_ordering(): void
    {
        $this->makeUsers(120, 'Charlie');

        // page 2, per_page 30 → names should start at "Charlie 31"
        $req = Request::create('/rbac/users/search', 'GET', ['q' => 'charlie', 'page' => '2', 'per_page' => '30']);
        $json = app(UserSearchController::class)->index($req)->getData(true);

        $this->assertCount(30, ($json['data'] ?? []));
        $this->assertSame('Charlie 31', $json['data'][0]['name'] ?? null);
        $this->assertSame(2, $json['meta']['page'] ?? null);
        $this->assertSame(30, $json['meta']['per_page'] ?? null);
        $this->assertSame(120, $json['meta']['total'] ?? null);
        $this->assertSame(4, $json['meta']['total_pages'] ?? null);
    }

    public function test_query_filters_by_name_or_email_case_insensitive(): void
    {
        // Distinct sets to prove filtering
        $this->makeUsers(5, 'Delta');
        $this->makeUsers(5, 'Echo');

        // name match
        $req1 = Request::create('/rbac/users/search', 'GET', ['q' => 'delt']);
        $names1 = array_map(
            fn ($r) => $r['name'],
            (app(UserSearchController::class)->index($req1)->getData(true)['data'] ?? [])
        );
        $this->assertNotEmpty($names1);
        $this->assertTrue(collect($names1)->every(fn ($n) => stripos($n, 'delt') !== false));

        // email match
        $req2 = Request::create('/rbac/users/search', 'GET', ['q' => 'echo0']); // matches echo01..echo05 emails
        $emails2 = array_map(
            fn ($r) => $r['email'],
            (app(UserSearchController::class)->index($req2)->getData(true)['data'] ?? [])
        );
        $this->assertNotEmpty($emails2);
        $this->assertTrue(collect($emails2)->every(fn ($e) => stripos($e, 'echo0') !== false));
    }
}

