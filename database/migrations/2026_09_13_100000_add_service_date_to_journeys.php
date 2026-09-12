<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A journey is a route on a date.
 *
 * Until now a route WAS a journey: one seat request per member per route, one
 * trip per route. That holds only because recurring plans are refused by both
 * domains, and Phase 16 lifts that refusal — so the identity every dated thing
 * hangs on becomes `(route_id, service_date)`.
 *
 * THE OCCURRENCE IS A VALUE, NOT A ROW
 *
 * There is no `route_occurrences` table, and this migration is what keeps it
 * unnecessary. An occurrence would be a row carrying nothing but its own
 * identity: no per-date cancellation, no per-date seat override, no state of
 * its own. It would also add a create race — the row has to be conjured on
 * first use — and a second two-resource lock order to defend, when the route
 * row already serializes both capacity and trip creation. `service_date` lives
 * on the two tables that need it and nowhere else.
 *
 * WHAT THIS MIGRATION DOES NOT DO
 *
 * It enables nothing. Both recurrence guards stay exactly where they are, so
 * every row this schema can hold is still a one-off journey and every value
 * backfilled below is the route's own `departure_date`. Phase 16b is what makes
 * a second date reachable.
 *
 * THE BACKFILL IS TOTAL, AND THAT IS PROVABLE RATHER THAN HOPED
 *
 * `App\SeatRequests\RequestSeat` and `App\Trips\StartTrip` each refuse a route
 * whose recurrence is not `once`, so every existing row of both tables belongs
 * to a one-off route; and `routes_departure_shape_check` guarantees a one-off
 * route has a date. There is no row for which the value below is unknown, so
 * nothing has to be guessed, defaulted or left behind.
 *
 * WHY THE NEW CONSTRAINTS ARRIVE BEFORE THE OLD ONES LEAVE
 *
 * The composite uniques are strictly weaker than the ones they replace, so
 * existing data satisfies them the moment they are added. Adding first and
 * dropping second means the stricter rule is guarding the table for the whole
 * operation. Dropping first would open a window in which two rows the old rule
 * forbids could both land.
 */
return new class extends Migration
{
    public function up(): void
    {
        // 1. Nullable first: the column has to exist before anything can fill
        //    it, and `not null` cannot be true of a column nobody has written.
        Schema::table('seat_requests', function (Blueprint $table): void {
            $table->date('service_date')->nullable();
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->date('service_date')->nullable();
        });

        // 2. The date each existing journey already ran on, taken from the
        //    route that defines it. Deterministic: a one-off route has exactly
        //    one departure date, and every row here belongs to one.
        DB::statement(
            'update seat_requests
                set service_date = routes.departure_date
               from routes
              where routes.id = seat_requests.route_id'
        );

        DB::statement(
            'update trips
                set service_date = routes.departure_date
               from routes
              where routes.id = trips.route_id'
        );

        // 3. Now it can be required. A journey without a date is not a journey
        //    this system can address, so the column is as mandatory as the
        //    route it belongs to.
        DB::statement('alter table seat_requests alter column service_date set not null');
        DB::statement('alter table trips alter column service_date set not null');

        // 4. The dated uniqueness, added while the old rule still holds.
        Schema::table('seat_requests', function (Blueprint $table): void {
            // One asking per member per DATED journey. Monday and Tuesday on
            // the same plan are two journeys, and a member may ask about each.
            $table->unique(
                ['route_id', 'service_date', 'account_id'],
                'seat_requests_one_per_journey_per_member',
            );
        });

        Schema::table('trips', function (Blueprint $table): void {
            // At most one trip per dated journey, which is what `unique
            // (route_id)` used to say while a route had only one.
            $table->unique(['route_id', 'service_date'], 'trips_one_per_journey');
        });

        // 5. Only now are the route-scoped rules removed.
        Schema::table('seat_requests', function (Blueprint $table): void {
            $table->dropUnique('seat_requests_one_per_route_per_member');
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->dropUnique('trips_route_id_unique');
        });

        // 6. `trips.route_id` has just lost the only index it had. PostgreSQL
        //    does not index a foreign key column on its own, and the trip
        //    lookups are all route-scoped, so one is added explicitly. The
        //    foreign key itself was never affected: it depends on the primary
        //    key of `routes`, not on any index of this table.
        Schema::table('trips', function (Blueprint $table): void {
            $table->index('route_id');
        });
    }

    /**
     * Reverse the schema, or refuse — never repair.
     *
     * The constraints added above permit rows the old ones cannot represent:
     * two requests from one member on one route for different dates, and two
     * trips on one route. Once Phase 16b has produced any of those, restoring
     * `unique (route_id, account_id)` or `unique (route_id)` is not a schema
     * change, it is a demand that data be deleted, merged or rewritten to fit.
     *
     * This rollback does none of those things. It looks first, and if the data
     * has outgrown the old shape it stops with nothing changed and says which
     * rows would have had to be destroyed. A migration that quietly collapsed
     * two members' journeys into one to make an index build would be a far
     * worse failure than a rollback that does not run.
     */
    public function down(): void
    {
        $requests = (int) DB::scalar(
            'select count(*) from (
                 select 1
                   from seat_requests
               group by route_id, account_id
                 having count(*) > 1
             ) as conflicting'
        );

        $trips = (int) DB::scalar(
            'select count(*) from (
                 select 1
                   from trips
               group by route_id
                 having count(*) > 1
             ) as conflicting'
        );

        if ($requests > 0 || $trips > 0) {
            throw new RuntimeException(sprintf(
                'Refusing to roll back: %d route/member pairs hold seat requests on more than '
                .'one service date and %d routes hold more than one trip. Restoring the '
                .'route-scoped uniqueness would require deleting or merging those rows, and no '
                .'migration here does that. Remove the dated journeys deliberately first, or '
                .'stay on this schema.',
                $requests,
                $trips,
            ));
        }

        // Lossless from here: the data already satisfies the old rules.
        Schema::table('trips', function (Blueprint $table): void {
            $table->dropIndex('trips_route_id_index');
        });

        Schema::table('seat_requests', function (Blueprint $table): void {
            $table->unique(['route_id', 'account_id'], 'seat_requests_one_per_route_per_member');
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->unique('route_id', 'trips_route_id_unique');
        });

        Schema::table('seat_requests', function (Blueprint $table): void {
            $table->dropUnique('seat_requests_one_per_journey_per_member');
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->dropUnique('trips_one_per_journey');
        });

        Schema::table('seat_requests', function (Blueprint $table): void {
            $table->dropColumn('service_date');
        });

        Schema::table('trips', function (Blueprint $table): void {
            $table->dropColumn('service_date');
        });
    }
};
