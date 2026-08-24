<?php

declare(strict_types=1);

namespace App\Routes;

use App\Models\Account;
use App\Models\Place;
use App\Models\Route;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Puts a driver's journey on the record.
 *
 * An action rather than controller code because it decides two things with
 * consequences: whether a departure is still in the future, and whether an id
 * that already exists represents this same publication or a different one. Both
 * are domain judgements, and both must read the same way whether they are
 * reached from HTTP or from a console command.
 *
 * IDEMPOTENT ON THE CLIENT'S ID
 *
 * A publication retried over a dropped connection arrives with the id the
 * client already generated. Same owner and same journey means the existing
 * route is returned untouched — the retry succeeded the first time and the
 * member should see one journey, not two. Anything else under that id is a
 * conflict, refused rather than merged: the id was minted to identify one
 * intended journey, so a second meaning for it is a client defect, and
 * inventing a second row would publish something nobody asked for.
 *
 * The whole decision runs inside one transaction with the row locked, because
 * the case it exists for is two identical requests arriving at once.
 */
final class PublishRoute
{
    /**
     * @throws RoutePublicationRefused
     */
    public function __invoke(
        Account $driver,
        string $id,
        Place $origin,
        Place $destination,
        RouteDeparture $departure,
        int $seatsOffered,
        RideRules $rules,
        ?CarbonImmutable $now = null,
    ): PublishedRoute {
        if ($origin->id === $destination->id) {
            throw RoutePublicationRefused::endpointsAreTheSame();
        }

        if ($seatsOffered < 1) {
            throw RoutePublicationRefused::seatsBelowFloor();
        }

        if (! $departure->isUpcoming($now)) {
            // Read in the route's own timezone, not the server's and not the
            // caller's. A journey that has already left cannot be published.
            throw RoutePublicationRefused::departureHasPassed();
        }

        return DB::transaction(function () use (
            $driver, $id, $origin, $destination, $departure, $seatsOffered, $rules, $now
        ): PublishedRoute {
            $existing = Route::query()->lockForUpdate()->find($id);

            if ($existing instanceof Route) {
                return $this->resolveExisting(
                    $existing, $driver, $origin, $destination, $departure, $seatsOffered, $rules,
                );
            }

            $route = new Route;

            // The client's id, assigned before save so HasUuids leaves it
            // alone. This is the idempotency key.
            $route->id = $id;
            $route->account_id = $driver->id;
            $route->origin_place_id = $origin->id;
            $route->destination_place_id = $destination->id;
            $route->recurrence = $departure->recurrence;
            $route->departure_date = $departure->dateValue();
            $route->departure_time = $departure->time;
            $route->timezone = $departure->timezone;
            $route->seats_offered = $seatsOffered;
            $route->status = RouteStatus::Published;
            $route->published_at = $now ?? CarbonImmutable::now();

            foreach ($rules->toColumns() as $column => $value) {
                $route->setAttribute($column, $value);
            }

            $route->save();

            return new PublishedRoute($route, wasAlreadyPublished: false);
        });
    }

    /**
     * @throws RoutePublicationRefused
     */
    private function resolveExisting(
        Route $existing,
        Account $driver,
        Place $origin,
        Place $destination,
        RouteDeparture $departure,
        int $seatsOffered,
        RideRules $rules,
    ): PublishedRoute {
        if ($existing->account_id !== $driver->id) {
            // Says only that the id is taken. Whose it is, and what it points
            // at, are none of this caller's business.
            throw RoutePublicationRefused::idBelongsToSomeoneElse();
        }

        $sameJourney = $existing->origin_place_id === $origin->id
            && $existing->destination_place_id === $destination->id
            && $existing->recurrence === $departure->recurrence
            && $existing->departure_date?->format(RouteDeparture::DATE_FORMAT) === $departure->date
            && substr($existing->departure_time, 0, 5) === $departure->time
            && $existing->seats_offered === $seatsOffered
            && $this->rulesMatch($existing, $rules);

        if (! $sameJourney) {
            throw RoutePublicationRefused::idDescribesADifferentJourney();
        }

        // The first attempt landed. Nothing is written, not even a timestamp:
        // a retry is the same publication arriving twice, not a new event.
        return new PublishedRoute($existing, wasAlreadyPublished: true);
    }

    private function rulesMatch(Route $existing, RideRules $rules): bool
    {
        foreach ($rules->toColumns() as $column => $value) {
            if ($existing->getAttribute($column) !== $value) {
                return false;
            }
        }

        return true;
    }
}
