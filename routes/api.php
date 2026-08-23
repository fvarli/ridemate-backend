<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RefreshController;
use App\Http\Controllers\Api\V1\Auth\RequestPasscodeController;
use App\Http\Controllers\Api\V1\Auth\VerifyPasscodeController;
use Illuminate\Support\Facades\Route;

/*
 * The versioned product API — /api/v1.
 *
 * Every route here is described by openapi/openapi.yaml, which was written and
 * reviewed before any of this existed. The contract leads; these are its
 * implementation.
 *
 * ON THE MIDDLEWARE
 *
 * Throttles are named rather than inline so their budgets live together in
 * config and cannot drift apart route by route. See AppServiceProvider.
 *
 * `auth.token` appears on exactly one route. Authentication is opt-IN rather
 * than global-with-exceptions, because the passcode endpoints cannot be
 * authenticated by definition — and a global guard with a list of exemptions
 * fails open the day someone adds a route and forgets to exempt it. This way
 * the mistake is a route that refuses everyone, which is noticed immediately.
 *
 * Refresh deliberately carries no auth middleware: its credential is in the
 * body. See RefreshController.
 */

Route::post('auth/otp', RequestPasscodeController::class)
    ->middleware('throttle:rm-otp-request')
    ->name('auth.otp.request');

Route::post('auth/otp/verify', VerifyPasscodeController::class)
    ->middleware('throttle:rm-otp-verify')
    ->name('auth.otp.verify');

Route::post('auth/refresh', RefreshController::class)
    ->middleware('throttle:rm-auth-refresh')
    ->name('auth.refresh');

Route::post('auth/logout', LogoutController::class)
    ->middleware('auth.token')
    ->name('auth.logout');
