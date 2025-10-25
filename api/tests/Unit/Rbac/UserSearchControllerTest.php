<?php

declare(strict_types=1);

namespace Tests\Unit\Rbac;

use App\Http\Controllers\Rbac\UserSearchController;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class UserSearchControllerTest extends TestCase
{
    use RefreshDatabase;

    private function makeUsers(int $n = 30, string $prefix = 'User', ?string $passwordHash = null): void
    {
        $password = $passwordHash ?? bcrypt('secret');

        for ($i = 1; $i <= $n; $i++) {
            User::query()->create([
                'name' => sprintf('%s %02d', $prefix, $i),
                'email' => sprintf('%s%02d@example.test', strtolower($prefix), $i),
                'password' => $password,
            ]);
        }
    }

    public function test_default_pagination_and_shape(): void
    {
        $this->makeUsers(80, 'Alpha');

        $req = Request::create('/rbac/users/search', 'GET', ['q' => 'alpha']); // matches all
        $jsonResponse = $this->callController($req);

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
        // Precomputed bcrypt hash for "secret" to avoid repeated hashing cost during setup
        $hashedSecret = '$2y$10$ALqzzppk9SDveM0fy6CEgeq4o.CX.gm7RGcDN/2U.br4xL.0GGvyK';
        $this->makeUsers(600, 'Bravo', $hashedSecret);

        // numeric string
        $req1 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'per_page' => '7']);
        $res1 = $this->callController($req1);
        $this->assertCount(7, ($res1->getData(true)['data'] ?? []));

        // array value → first numeric element
        $req2 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'per_page' => ['5', '9']]);
        $res2 = $this->callController($req2);
        $this->assertCount(5, ($res2->getData(true)['data'] ?? []));

        // invalid → fallback default 50
        $req3 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'per_page' => 'abc']);
        $res3 = $this->callController($req3);
        $this->assertCount(50, ($res3->getData(true)['data'] ?? []));

        // lower bound clamp (0 → 1)
        $req4 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'per_page' => '0']);
        $res4 = $this->callController($req4);
        $this->assertCount(1, ($res4->getData(true)['data'] ?? []));

        // upper bound clamp (>500 → 500)
        $req5 = Request::create('/rbac/users/search', 'GET', ['q' => 'bravo', 'per_page' => '1000']);
        $res5 = $this->callController($req5);
        $this->assertCount(500, ($res5->getData(true)['data'] ?? []));
    }

    public function test_page_offsetting_and_ordering(): void
    {
        $this->makeUsers(120, 'Charlie');

        // page 2, per_page 30 → names should start at "Charlie 31"
        $req = Request::create('/rbac/users/search', 'GET', ['q' => 'charlie', 'page' => '2', 'per_page' => '30']);
        $json = $this->callController($req)->getData(true);

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
            ($this->callController($req1)->getData(true)['data'] ?? [])
        );
        $this->assertNotEmpty($names1);
        $this->assertTrue(collect($names1)->every(fn ($n) => stripos($n, 'delt') !== false));

        // email match
        $req2 = Request::create('/rbac/users/search', 'GET', ['q' => 'echo0']); // matches echo01..echo05 emails
        $emails2 = array_map(
            fn ($r) => $r['email'],
            ($this->callController($req2)->getData(true)['data'] ?? [])
        );
        $this->assertNotEmpty($emails2);
        $this->assertTrue(collect($emails2)->every(fn ($e) => stripos($e, 'echo0') !== false));
    }

    public function test_query_supports_field_filters_and_role_lookup(): void
    {
        $this->makeUsers(5, 'Foxtrot');
        $this->makeUsers(5, 'Golf');

        /** @var User $foxtrotOne */
        $foxtrotOne = User::query()->where('email', 'foxtrot01@example.test')->firstOrFail();
        /** @var User $golfThree */
        $golfThree = User::query()->where('email', 'golf03@example.test')->firstOrFail();

        Role::query()->create(['id' => 'role_test', 'name' => 'test']);
        $foxtrotOne->roles()->sync(['role_test']);

        $nameReq = Request::create('/rbac/users/search', 'GET', ['q' => 'name:"Foxtrot 01"']);
        $nameJson = $this->callController($nameReq)->getData(true);
        $this->assertCount(1, $nameJson['data'] ?? []);
        $this->assertSame($foxtrotOne->id, $nameJson['data'][0]['id'] ?? null);

        $emailReq = Request::create('/rbac/users/search', 'GET', ['q' => 'email:golf03@example.test']);
        $emailJson = $this->callController($emailReq)->getData(true);
        $this->assertCount(1, $emailJson['data'] ?? []);
        $this->assertSame($golfThree->id, $emailJson['data'][0]['id'] ?? null);

        $idReq = Request::create('/rbac/users/search', 'GET', ['q' => 'id:'.$golfThree->id]);
        $idJson = $this->callController($idReq)->getData(true);
        $this->assertCount(1, $idJson['data'] ?? []);
        $this->assertSame($golfThree->id, $idJson['data'][0]['id'] ?? null);

        $roleReq = Request::create('/rbac/users/search', 'GET', ['q' => 'role:test']);
        $roleJson = $this->callController($roleReq)->getData(true);
        $this->assertCount(1, $roleJson['data'] ?? []);
        $this->assertSame($foxtrotOne->id, $roleJson['data'][0]['id'] ?? null);

        $generalRoleReq = Request::create('/rbac/users/search', 'GET', ['q' => 'test']);
        $generalRoleJson = $this->callController($generalRoleReq)->getData(true);
        $this->assertNotEmpty($generalRoleJson['data'] ?? []);
        $this->assertSame($foxtrotOne->id, $generalRoleJson['data'][0]['id'] ?? null);
    }

    public function test_query_anchors_terms_and_supports_wildcards(): void
    {
        User::query()->create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => bcrypt('secret'),
        ]);

        $jane = User::query()->create([
            'name' => 'Jane Doe',
            'email' => 'janedoe@test.com',
            'password' => bcrypt('secret'),
        ]);

        Role::query()->create(['id' => 'role_example', 'name' => 'example']);
        $jane->roles()->sync(['role_example']);

        $cases = [
            'a' => ['admin@example.com'],
            'ja' => ['janedoe@test.com'],
            '*ex' => ['admin@example.com', 'janedoe@test.com'],
            '.ane' => ['janedoe@test.com'],
            '*@.t' => ['janedoe@test.com'],
        ];

        foreach ($cases as $query => $expectedEmails) {
            $json = $this->callController(Request::create('/rbac/users/search', 'GET', ['q' => $query]))->getData(true);
            $emails = array_map(static fn ($r) => $r['email'] ?? null, $json['data'] ?? []);

            foreach ($expectedEmails as $email) {
                $this->assertContains($email, $emails, sprintf('Query "%s" should include %s', $query, $email));
            }
        }

        $aResult = $this->callController(Request::create('/rbac/users/search', 'GET', ['q' => 'a']))->getData(true);
        $aEmails = array_map(static fn ($r) => $r['email'] ?? null, $aResult['data'] ?? []);
        $this->assertNotContains('janedoe@test.com', $aEmails);

        $jaResult = $this->callController(Request::create('/rbac/users/search', 'GET', ['q' => 'ja']))->getData(true);
        $jaEmails = array_map(static fn ($r) => $r['email'] ?? null, $jaResult['data'] ?? []);
        $this->assertNotContains('admin@example.com', $jaEmails);
    }

    private function callController(Request $request): JsonResponse
    {
        /** @var UserSearchController $controller */
        $controller = app(UserSearchController::class);

        return $controller->index($request);
    }
}
