<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A journey a driver has published.
 *
 * THE ID COMES FROM THE CLIENT
 *
 * Unusually, and deliberately. The client generates a UUIDv7 before it posts,
 * and that id IS the idempotency mechanism: a publication retried over a
 * dropped connection arrives with the same id and cannot become a second
 * journey. The alternative — an Idempotency-Key header with a replay table —
 * is a subsystem, and the primary key already answers the same question.
 *
 * A PLAN, NOT AN INSTANT
 *
 * `departure_time` is a wall clock and `departure_date` is present only for a
 * one-off journey, because a weekday commute has no single moment to store.
 * `timezone` records the zone that wall clock is read in, so a second city
 * cannot silently reinterpret the first one's rows.
 *
 * WHAT IS DELIBERATELY ABSENT
 *
 * No cost column of any kind. The screen shows a suggested per-person share,
 * but no driver chooses it and no approved policy computes it, so storing a
 * number here would manufacture a fact. RideMate shares journey costs; it
 * charges nobody.
 *
 * No `expired` status and no scheduled sweep — see App\Routes\RouteStatus. No
 * seat-request columns, no vehicle, no participants: nothing in this phase
 * produces or consumes them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('routes', function (Blueprint $table): void {
            // Client-supplied UUIDv7, validated at the boundary. Time-ordered
            // like every other id here, but the application does not mint it.
            $table->uuid('id')->primary();

            // The driver. Ownership comes from the authenticated credential,
            // never from a request field.
            $table->foreignUuid('account_id')->constrained('accounts')->cascadeOnDelete();

            // Reference, not snapshot: a place cannot move, so a route cannot
            // be relocated by an edit. See the places migration.
            $table->foreignUuid('origin_place_id')->constrained('places')->restrictOnDelete();
            $table->foreignUuid('destination_place_id')->constrained('places')->restrictOnDelete();

            $table->string('recurrence', 16);

            // Null for a recurring journey. The check constraint below makes
            // that an invariant rather than a convention.
            $table->date('departure_date')->nullable();

            // Always present, in both modes: the driver chose a time, and the
            // fixture 08:00 the screen used to show is not a choice anyone made.
            $table->time('departure_time');

            // The IANA zone the wall clock above is read in, copied from the
            // pilot configuration at publication. Stored per row so historical
            // journeys keep their meaning when a second city arrives.
            $table->string('timezone', 64);

            // Free seats the driver is offering. `smallint` is the storage
            // bound and NOT a product rule: the approved screen has a floor of
            // one and deliberately no ceiling, because any maximum would invent
            // a vehicle-capacity rule that no design states. When a vehicle
            // model exists, the real limit arrives as domain data.
            $table->smallInteger('seats_offered');

            // The four rules the screen collects, as four columns rather than a
            // jsonb blob: they are a closed set the UI already fixes, and a
            // column can be constrained, indexed and read without a cast.
            //
            // POLICY REVIEW OUTSTANDING for rule_no_pets. The client carries
            // kRuleNeedingPolicyReview against the same rule, and that marker
            // travels with the data rather than being dropped at the boundary.
            // This records the open question; it does not answer it, and
            // nothing here asserts what any law requires.
            $table->boolean('rule_no_smoking');
            $table->boolean('rule_music_ok');
            $table->boolean('rule_no_pets');
            $table->boolean('rule_quiet');

            $table->string('status', 16);

            $table->timestampTz('published_at');
            $table->timestampTz('cancelled_at')->nullable();

            $table->timestampsTz();

            // The My Routes keyset: newest first, per owner. Ordered by the
            // SERVER's created_at rather than the id, because the id is the
            // client's and a client could emit them out of order.
            $table->index(['account_id', 'created_at', 'id']);
        });

        // Blueprint cannot express any of these, and an application enum is not
        // a database guarantee: a console command or a hand-written UPDATE
        // would bypass it.
        DB::statement(
            "alter table routes add constraint routes_recurrence_check
             check (recurrence in ('once', 'weekdays'))"
        );

        DB::statement(
            "alter table routes add constraint routes_status_check
             check (status in ('published', 'cancelled'))"
        );

        // The rule that keeps a plan honest: a dated journey has a date, and a
        // recurring one does not. Without this, a row could claim both at once
        // and no reader could tell which half to believe.
        DB::statement(
            "alter table routes add constraint routes_departure_shape_check
             check (
                 (recurrence = 'once' and departure_date is not null)
                 or (recurrence = 'weekdays' and departure_date is null)
             )"
        );

        // A journey from a place to itself is not a journey.
        DB::statement(
            'alter table routes add constraint routes_distinct_endpoints_check
             check (origin_place_id <> destination_place_id)'
        );

        // One is the floor the screen enforces. There is no ceiling here for
        // the same reason there is none there.
        DB::statement(
            'alter table routes add constraint routes_seats_offered_check
             check (seats_offered >= 1)'
        );

        // Cancellation is a fact with a time, or it has not happened. A status
        // of cancelled with no timestamp would lose when it happened; a
        // timestamp on a published route would claim something that did not.
        DB::statement(
            "alter table routes add constraint routes_cancellation_check
             check (
                 (status = 'cancelled' and cancelled_at is not null)
                 or (status = 'published' and cancelled_at is null)
             )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('routes');
    }
};
