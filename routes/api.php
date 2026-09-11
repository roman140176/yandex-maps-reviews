<?php

declare(strict_types=1);

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\OrganizationController;
use App\Http\Controllers\Api\ParseRunController;
use App\Http\Controllers\Api\ReviewChangeController;
use App\Http\Controllers\Api\ReviewController;
use Illuminate\Support\Facades\Route;

Route::post('auth/login', [AuthController::class, 'login'])->name('auth.login');

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('auth/me', [AuthController::class, 'me'])->name('auth.me');
    Route::post('auth/logout', [AuthController::class, 'logout'])->name('auth.logout');

    Route::apiResource('organizations', OrganizationController::class)
        ->except(['update']);

    Route::post('organizations/{organization}/refresh', [OrganizationController::class, 'refresh'])
        ->name('organizations.refresh');

    Route::get('organizations/{organization}/reviews', [ReviewController::class, 'index'])
        ->name('organizations.reviews');

    Route::get('organizations/{organization}/runs', [ParseRunController::class, 'index'])
        ->name('organizations.runs');

    Route::get('organizations/{organization}/changes', [ReviewChangeController::class, 'index'])
        ->name('organizations.changes');
});
