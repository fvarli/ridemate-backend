<?php

declare(strict_types=1);

use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

/*
 * Operational endpoints.
 *
 * Deliberately outside /api/v1: they are not part of the product API, they are
 * not versioned with it, and an orchestrator probing them should never have to
 * follow an API version bump.
 */

Route::get('/health', [HealthController::class, 'health'])->name('ops.health');
Route::get('/ready', [HealthController::class, 'ready'])->name('ops.ready');
