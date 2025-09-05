<?php

declare(strict_types=1);

/**
 * Phase 2 — Sanctum SPA scaffold (kept inert).
 * - Keep 'web' session guard active.
 * - Provide commented 'api' guard using Sanctum for later enablement.
 * - No password reset providers in this phase.
 */
return [
    'defaults' => [
        'guard' => 'web',
        'passwords' => 'users',
    ],

    'guards' => [
        'web' => [
            'driver'   => 'session',
            'provider' => 'users',
        ],

        // Enable later for SPA:
        // 'api' => [
        //     'driver'   => 'sanctum',
        //     'provider' => 'users',
        // ],
    ],

    'providers' => [
        'users' => [
            'driver' => 'eloquent',
            'model'  => App\Models\User::class,
        ],
    ],

    // Out of scope in Phase 2
    'passwords' => [],
];
