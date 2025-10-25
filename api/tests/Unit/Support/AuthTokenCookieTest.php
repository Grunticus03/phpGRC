<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\AuthTokenCookie;
use Illuminate\Http\Request;
use Tests\TestCase;

final class AuthTokenCookieTest extends TestCase
{
    public function test_issue_cookie_defaults_to_secure_lax_when_request_is_secure(): void
    {
        config()->set('session.secure', false);
        config()->set('core.auth.token_cookie.secure', 'auto');
        config()->set('core.auth.token_cookie.same_site', null);

        $request = Request::create('/', 'GET', [], [], [], ['HTTPS' => 'on']);

        $cookie = AuthTokenCookie::issue('token', $request);

        self::assertTrue($cookie->isSecure());
        self::assertSame('lax', strtolower((string) $cookie->getSameSite()));
    }

    public function test_issue_cookie_forces_secure_when_same_site_is_none(): void
    {
        config()->set('session.secure', false);
        config()->set('core.auth.token_cookie.secure', 'auto');
        config()->set('core.auth.token_cookie.same_site', 'none');

        $request = Request::create('/', 'GET');

        $cookie = AuthTokenCookie::issue('token', $request);

        self::assertTrue($cookie->isSecure());
        self::assertSame('none', strtolower((string) $cookie->getSameSite()));
    }

    public function test_issue_cookie_respects_explicit_secure_override(): void
    {
        config()->set('session.secure', true);
        config()->set('core.auth.token_cookie.secure', false);

        $request = Request::create('/', 'GET', [], [], [], ['HTTPS' => 'on']);

        $cookie = AuthTokenCookie::issue('token', $request);

        self::assertFalse($cookie->isSecure());
    }

    public function test_issue_cookie_inherits_session_secure_when_requested(): void
    {
        config()->set('session.secure', 'true');
        config()->set('core.auth.token_cookie.secure', 'inherit');

        $request = Request::create('/', 'GET');

        $cookie = AuthTokenCookie::issue('token', $request);

        self::assertTrue($cookie->isSecure());
    }
}
