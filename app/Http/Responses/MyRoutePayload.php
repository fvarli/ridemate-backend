<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\Route;
use App\Trips\TripLifecycle;
use Carbon\CarbonImmutable;

/**
 * A journey as its own driver sees it in My Routes.
 *
 * Everything `RoutePayload` publishes, plus whether the journey was actually
 * made.
 *
 * WHY THIS IS NOT A FLAG ON `RoutePayload`
 *
 * That class is also the publish and the cancel response, and Phase 14 put the
 * lifecycle on exactly two surfaces: this one and a passenger's own seat
 * requests. Adding a field there widened three endpoints to serve two, which is
 * how a shared payload starts meaning different things at different call sites
 * — and an optional-field switch would only move that ambiguity into a
 * parameter. Composition keeps the base projection exactly what it was and puts
 * the extra fact where it was asked for.
 *
 * Owner-only by construction: nothing outside My Routes builds this.
 */
final class MyRoutePayload
{
    /**
     * @return array<string, mixed>
     */
    public static function from(Route $route, ?CarbonImmutable $now = null): array
    {
        return RoutePayload::from($route, $now) + [
            'trip' => TripPayload::from(TripLifecycle::of($route->trip)),
        ];
    }
}
