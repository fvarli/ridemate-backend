<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A journey actually being made.
 *
 * A route is a plan and a seat request is somebody asking to come along. This
 * is the third thing: whether the journey happened. Keeping it a separate row
 * is what stops `routes.status` from acquiring travel states it has no business
 * holding — a published route that is under way is still published, and saying
 * so in one column would make "cancelled" and "completed" alternatives to each
 * other when they are answers to different questions.
 *
 * `not_started` IS NOT A ROW
 *
 * It is what the absence of one means. Writing it would need something to
 * create a row for every route the moment it is published, and then keep that
 * row true — the same objection `App\Routes\RouteStatus` makes to `expired`.
 * The lifecycle projection turns "no row" into `not_started` on the way out.
 *
 * ONE TRIP PER ROUTE, AND ONLY ONE
 *
 * `route_id` is unique. Phase 14 supports one-off journeys only, so a route is
 * made at most once and there is nothing to disambiguate. When recurring
 * journeys eventually gain occurrences, a trip will belong to an occurrence
 * rather than to a route, and this constraint is what will force that change to
 * be deliberate rather than accidental.
 *
 * WHAT IS DELIBERATELY ABSENT
 *
 * No participants table and no snapshot of who was aboard: accepted seat
 * requests remain the single source of participation, and a second copy could
 * only disagree with the first. No coordinates, no distance, no duration, no
 * abort reason — `in_progress` means a driver pressed a button, and nothing
 * here knows where anybody is. No cost or earnings, for the reason `routes`
 * gives at greater length.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trips', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Unique: at most one trip per route. Cascade because a trip has no
            // meaning without the journey it is the making of.
            $table->foreignUuid('route_id')
                ->unique()
                ->constrained('routes')
                ->cascadeOnDelete();

            $table->string('status', 16);

            // Not nullable: a row exists only because a trip started, so there
            // is no state this table can hold without a start time.
            $table->timestampTz('started_at');

            $table->timestampTz('completed_at')->nullable();

            $table->timestampTz('aborted_at')->nullable();

            $table->timestampsTz();
        });

        // Blueprint cannot express these, and an application enum is not a
        // database guarantee: a console command or a hand-written UPDATE would
        // bypass it. Same reasoning as `routes` and `seat_requests`.
        DB::statement(
            "alter table trips add constraint trips_status_check
             check (status in ('in_progress', 'completed', 'aborted'))"
        );

        // A completed trip carries its completion time, and one that is not
        // completed must not. Without this a row could claim to be under way
        // while holding the moment it finished.
        DB::statement(
            "alter table trips add constraint trips_completion_shape_check
             check ((status = 'completed') = (completed_at is not null))"
        );

        DB::statement(
            "alter table trips add constraint trips_abortion_shape_check
             check ((status = 'aborted') = (aborted_at is not null))"
        );

        // A JOURNEY ENDS ONCE, AND THE THREE ABOVE ALREADY SAY SO.
        //
        // A fourth constraint reading `completed_at is null or aborted_at is
        // null` was written and then removed: no row can reach it. A status is
        // one of three, and for each of them one shape check demands a
        // timestamp while the other forbids one, so a row carrying both endings
        // is rejected before exclusivity is ever consulted. A mutation test
        // proved it — deleting that fourth constraint failed nothing.
        //
        // It is left out rather than kept as defence, because a constraint no
        // row can violate is a claim nobody can check. A future third terminal
        // state will need its own shape check, and that is what will keep this
        // true.
    }

    public function down(): void
    {
        Schema::dropIfExists('trips');
    }
};
