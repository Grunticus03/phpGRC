<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => response()->json(['ok' => true, 'service' => 'phpGRC API web route stub']));
