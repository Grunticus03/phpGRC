<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use App\Http\Middleware\Auth\TokenCookieGuard;
use App\Support\AuthTokenCookie;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Tests\TestCase;

final class TokenCookieGuardTest extends TestCase
{
    public function test_sets_bearer_header_from_cookie(): void
    {
        $middleware = new TokenCookieGuard();
        $request = Request::create('/dummy', 'GET');
        $request->cookies->set(AuthTokenCookie::name(), 'abc|def');

        $captured = null;

        $middleware->handle($request, function (Request $req) use (&$captured): Response {
            $captured = $req->bearerToken();
            return new Response();
        });

        $this->assertSame('abc|def', $captured);
        $this->assertSame('Bearer abc|def', $request->headers->get('Authorization'));
        $this->assertSame('Bearer abc|def', $request->server->get('HTTP_AUTHORIZATION'));
    }

    public function test_skips_when_cookie_missing(): void
    {
        $middleware = new TokenCookieGuard();
        $request = Request::create('/dummy', 'GET');

        $middleware->handle($request, function (Request $req): Response {
            $this->assertNull($req->bearerToken());
            return new Response();
        });

        $this->assertNull($request->headers->get('Authorization'));
    }
}
