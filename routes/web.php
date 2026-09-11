<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

/*
 * The SPA is served from the same origin as the API, which is what lets Sanctum
 * authenticate it with an ordinary session cookie. Every non-API path returns
 * the same shell and Vue Router takes it from there.
 */
Route::view('/{any?}', 'app')
    ->where('any', '^(?!api|storage|up).*$')
    ->name('spa');
