<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['ok' => true, 'service' => 'phpGRC API web route stub']));

Route::get('/auth/callback', static function () {
    $indexPath = public_path('index.html');
    if (! is_string($indexPath) || $indexPath === '' || ! file_exists($indexPath)) {
        abort(404);
    }

    return response()->file($indexPath);
});
