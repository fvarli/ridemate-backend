<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Routes;

use App\Auth\AuthContext;
use App\Http\Requests\PublishRouteRequest;
use App\Http\Responses\RoutePayload;
use App\Models\Place;
use App\Routes\PublishRoute;
use App\Routes\RefusalReason;
use App\Routes\RoutePublicationRefused;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/**
 * `POST /api/v1/routes`.
 *
 * TWO SUCCESSES, AND THE DIFFERENCE IS REAL
 *
 * 201 when this request created the journey, 200 when a retry found the one it
 * had already created. The client generated the id before it sent anything, so
 * a request repeated over a dropped connection carries the same id and resolves
 * to the same journey instead of a second one. That is the whole idempotency
 * mechanism: no header, no replay table, just a primary key that already meant
 * something.
 *
 * The driver comes from the credential and from nowhere else. The request has
 * no owner field, and the timezone is the pilot's rather than the caller's.
 *
 * WHY THIS TRANSLATES THE DOMAIN'S REFUSAL
 *
 * `App\Routes` throws `RoutePublicationRefused`, which knows why it refused but
 * nothing about HTTP — deliberately, so a console command could publish a route
 * without a status code being involved. Somebody has to map the reason to an
 * answer, and this is the layer that speaks HTTP. The match is total over a
 * two-case enum, so a third reason cannot be added without this failing to
 * compile.
 *
 * In practice `InvalidJourney` is a backstop: identical endpoints, a seat count
 * below one and a departure that has passed are all validated in the request,
 * where they become field errors a client can render next to the control that
 * caused them.
 */
final class PublishRouteController
{
    public function __invoke(PublishRouteRequest $request, PublishRoute $publish): JsonResponse
    {
        $driver = AuthContext::of($request)->account;

        try {
            $published = $publish(
                $driver,
                $request->routeId(),
                Place::query()->findOrFail($request->originPlaceId()),
                Place::query()->findOrFail($request->destinationPlaceId()),
                $request->departure(),
                $request->seatsOffered(),
                $request->rideRules(),
            );
        } catch (RoutePublicationRefused $refused) {
            throw match ($refused->reason) {
                // Says the id is taken and nothing more. Whose it is, and what
                // it describes, belong to somebody else.
                RefusalReason::IdAlreadyUsed => new ConflictHttpException(
                    'That route id is already in use.',
                ),
                RefusalReason::InvalidJourney => ValidationException::withMessages([
                    'id' => [$refused->getMessage()],
                ]),
            };
        }

        $route = $published->route->load(['originPlace', 'destinationPlace']);

        return new JsonResponse(
            RoutePayload::envelope($route),
            $published->wasAlreadyPublished ? 200 : 201,
        );
    }
}
