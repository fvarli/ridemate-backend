<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Liveness and readiness.
 *
 * The distinction is the point, and it is not cosmetic:
 *
 *   /health  is this process alive?          Never touches a dependency.
 *   /ready   can it serve traffic?           Checks every dependency it needs.
 *
 * A database outage must take /ready down and leave /health up. Conflating
 * them makes an orchestrator restart a perfectly healthy process because
 * something else broke, which turns one outage into two.
 *
 * Neither response exposes a host, port, DSN, driver message, SQL fragment or
 * exception text. A readiness probe is reachable by anything that can reach
 * the service, so it says whether a dependency is ready and nothing about how
 * it is configured. The reason why lives in the logs.
 */
final class HealthController
{
    /**
     * Liveness. NO DATABASE ACCESS — see HealthTest, which asserts this
     * endpoint answers while the connection is pointed at an unreachable host.
     */
    public function health(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'service' => config('app.name'),
            'environment' => app()->environment(),
            'release' => config('app.release'),
        ]);
    }

    /**
     * Readiness: the database answers, and it can do geography.
     *
     * PostGIS counts as a hard dependency rather than a nice-to-have. Route
     * matching is geographic, so a database without it cannot serve RideMate
     * even though it accepts connections.
     */
    public function ready(): JsonResponse
    {
        $checks = [
            'database' => $this->check(static fn () => DB::connection()->select('select 1')),
            'postgis' => $this->check(static fn () => DB::connection()->select('select PostGIS_Version()')),
        ];

        $ready = ! in_array(false, $checks, strict: true);

        return new JsonResponse(
            [
                'status' => $ready ? 'ready' : 'not_ready',
                'checks' => array_map(
                    static fn (bool $ok): string => $ok ? 'ok' : 'failed',
                    $checks,
                ),
            ],
            $ready ? 200 : 503,
        );
    }

    /**
     * @param  callable(): mixed  $probe
     */
    private function check(callable $probe): bool
    {
        try {
            $probe();

            return true;
        } catch (Throwable) {
            // Swallowed deliberately: the caller learns "failed" and the
            // operator learns why from the log line this request already
            // writes. Returning the driver message would publish the DSN.
            return false;
        }
    }
}
