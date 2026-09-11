<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One member's self-declared rating about one completed relationship.
 *
 * It is not evidence that anybody boarded, was picked up, or travelled.
 * RideMate owns four facts about a journey — a route was published, a request
 * was accepted, a driver pressed Start, a driver pressed Complete — and every
 * one of them is the driver's own declaration. Nothing in the service proves a
 * passenger was in a car, and this table does not pretend otherwise.
 *
 * THE SEAT REQUEST IS THE CANONICAL RELATIONSHIP
 *
 * Not the route, which would admit reviews on a journey that never ran, and not
 * the trip, which cannot name WHICH passenger. A seat request names exactly two
 * people on exactly one journey, and reaches the trip through its route.
 *
 * THERE IS NO ACCOUNT COLUMN, AND THAT IS THE POINT
 *
 * Both identities are derived, each exactly one way:
 *
 *     passenger = seat_requests.account_id
 *     driver    = routes.account_id, for seat_requests.route_id
 *     reviewer  = whichever `reviewer_role` names; reviewee = the other
 *
 * Storing `reviewer_account_id` and `reviewee_account_id` beside the role would
 * permit combinations the seat request contradicts — a review whose reviewer is
 * neither party, or whose role disagrees with its account. Leaving the columns
 * out makes those rows unrepresentable rather than merely rejected in
 * application code: there is nowhere to write one. See `App\Reviews\ReviewParticipants`.
 *
 * The cost is that "reviews about me" is a two-join, role-dependent predicate.
 * It is keyset-pageable like every other feed, and if it ever becomes slow the
 * answer is a covering index, not a denormalised account id.
 *
 * WHAT IS DELIBERATELY ABSENT
 *
 * No `release_at` and no `counterpart_reviewed_at`: a review is released when
 * the counterpart row exists or the window has closed, and both are already
 * knowable — storing either would force the second submission to UPDATE the
 * first, which means a lock and a transaction in a command that otherwise needs
 * neither, and a second copy of a fact that can drift from the first.
 *
 * No `submitted_at`. A review is immutable once written, so `created_at` IS the
 * moment it was submitted; a second column would be the same instant twice.
 *
 * No text, no tags, no aggregate, no trust contribution, no moderation state,
 * no reply, no `route_id` or `trip_id` — each reachable through the seat
 * request, and a second path to the same row is a second thing to keep in
 * agreement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table): void {
            // Client-generated UUIDv7, like a route id and a seat request id.
            // It is the idempotency key: the same id is the same review, so a
            // retry after a lost response cannot become a second one.
            $table->uuid('id')->primary();

            // Cascade: a review has no meaning without the relationship it is
            // about, and nothing deletes a seat request today.
            $table->foreignUuid('seat_request_id')
                ->constrained('seat_requests')
                ->cascadeOnDelete();

            $table->string('reviewer_role', 16);

            $table->smallInteger('rating');

            $table->timestampsTz();

            // THE WHOLE CARDINALITY RULE, IN ONE LINE.
            //
            // One review per party per relationship: a seat request can hold
            // exactly two, one from each side. It is also what resolves the
            // create race — two simultaneous submissions collide here rather
            // than needing a lock, because both preconditions a review depends
            // on (an accepted request, a completed trip) are terminal and
            // cannot be invalidated underneath a writer.
            $table->unique(['seat_request_id', 'reviewer_role']);
        });

        // Blueprint cannot express these, and an application enum is not a
        // database guarantee: a console command or a hand-written UPDATE would
        // bypass it. Same reasoning as `routes`, `seat_requests` and `trips`.
        DB::statement(
            "alter table reviews add constraint reviews_reviewer_role_check
             check (reviewer_role in ('driver', 'passenger'))"
        );

        // One to five, whole. Not a decimal: the fixture's 4.8 is an AGGREGATE
        // artifact, and a fractional rating on a single review is an invitation
        // for some client to average them — the computation Phase 15 refuses to
        // own.
        DB::statement(
            'alter table reviews add constraint reviews_rating_check
             check (rating between 1 and 5)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reviews');
    }
};
