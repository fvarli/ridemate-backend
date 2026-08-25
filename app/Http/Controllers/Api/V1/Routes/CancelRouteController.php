<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Routes;

use App\Auth\AuthContext;
use App\Http\Responses\RoutePayload;
use App\Routes\CancelRoute;
use App\Routes\RouteCancellationRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * `POST /api/v1/routes/{routeId}/cancel`.
 *
 * No request body, because the transition names its own target state. Running
 * it again is not a second cancellation; it is the same one, seen twice.
 *
 * Three answers. 200 for a journey now cancelled — or already cancelled, which
 * is the same fact. 409 when the departure has passed, because withdrawing
 * something that already happened rewrites history rather than changing a plan.
 * 404 when the route is not the caller's, which covers "belongs to somebody
 * else" and "does not exist" with one answer, so neither can be told apart.
 *
 * The route id is constrained to a UUIDv7 by the route definition, so a
 * malformed one never reaches here — it is a 404 like any other unmatched path,
 * which is what the contract publishes for this operation. No 422 is documented
 * for cancellation and none is invented.
 */
final class CancelRouteController
{
    public function __invoke(Request $request, string $routeId, CancelRoute $cancel): JsonResponse
    {
        try {
            $route = $cancel(AuthContext::of($request)->account, $routeId);
        } catch (RouteCancellationRefused $refused) {
            throw new ConflictHttpException($refused->getMessage());
        }

        return new JsonResponse(RoutePayload::envelope($route));
    }
}
