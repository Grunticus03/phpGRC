# Sanctum SPA Mode — Enablement Notes

**Phase:** 2  
**Status:** Scaffolded and disabled by design.

## Enable later
1) **Env**
```
env
APP_URL=https://phpgrc.example.com
SESSION_DRIVER=cookie
SESSION_DOMAIN=.example.com
SESSION_SECURE_COOKIE=true
SESSION_SAME_SITE=lax
SANCTUM_STATEFUL_DOMAINS=app.example.com,phpgrc.example.com,localhost,127.0.0.1
```

2) **Auth guard**
Uncomment in config/auth.php
```
'api' => ['driver' => 'sanctum', 'provider' => 'users'],
```

3) **Sanctum config**
Optionally mirror domains in config/sanctum.php:
```
'stateful' => ['app.example.com','phpgrc.example.com','localhost','127.0.0.1'],
// 'guard' => ['web'],
```

4) **Kernel (when enabling)**
Add to app/Http/Kernel.php API group:
```
\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
```

5) **CSRF flow**
- GET /sanctum/csrf-cookie before auth calls.
- Send X-XSRF-TOKEN header on state-changing requests.
- CORS (when enabling)
- supports_credentials=true
- Add SPA origin to allowed_origins
- Include X-XSRF-TOKEN in allowed_headers

##Rollback
Comment the api guard and run:
```
php artisan config:clear && php artisan cache:clear
```