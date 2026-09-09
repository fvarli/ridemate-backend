<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A passenger asking a driver for a seat.
 *
 * THE ID COMES FROM THE CLIENT
 *
 * Same mechanism as `routes`, for the same reason: the client mints a UUIDv7
 * before it posts, and that id IS the idempotency key. A request retried over a
 * dropped connection arrives with the same id and cannot become a second
 * asking. See *Idempotency* in `docs/api-conventions.md` — this is tier 1, and
 * tier 3 is still not built because nothing here needs it.
 *
 * ONE REQUEST PER MEMBER PER ROUTE, FOR THE ROUTE'S LIFETIME
 *
 * `unique (route_id, account_id)` is deliberately NOT partial. A partial index
 * over the live states would quietly permit a new request after a decline or a
 * withdrawal, which is a re-request policy — how often, after how long, whether
 * a declining driver can be asked again — and nobody has decided one. Inventing
 * that policy as a side effect of an index shape is the failure this avoids: a
 * spent request is spent, exactly as a cancelled route id is spent.
 *
 * The consequence is real and was accepted knowingly: a passenger who withdraws
 * by mistake cannot ask again on that route. If the pilot shows that is too
 * sharp, a re-request policy gets designed and this constraint changes with it.
 *
 * DRIVER OWNERSHIP IS NOT STORED HERE
 *
 * There is no `driver_account_id`. The driver is whoever owns the route, and
 * `routes.account_id` already says so. A second copy could disagree with the
 * first after any future route transfer, and every authorization check would
 * then have to choose which copy to believe.
 *
 * WHAT IS DELIBERATELY ABSENT
 *
 * No seat quantity: one request is one seat, so there is no arithmetic to get
 * wrong and no partial acceptance to model. No message or note — that is chat,
 * and chat does not exist. No cost, fare or contribution, for the reason the
 * routes table gives at greater length. No occurrence reference: Phase 13 v1
 * accepts requests on one-off routes only, so there is nothing per-day to point
 * at and `route_occurrences` stays unbuilt. No read-state or notification
 * column: nothing notifies anybody yet, and a flag nothing sets is a lie with a
 * default value.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seat_requests', function (Blueprint $table): void {
            // The client's UUIDv7. Not generated here — see the note above.
            $table->uuid('id')->primary();

            // Cascade: a request has no meaning without the journey it is
            // about. It is not a record ABOUT the route, it is a request FOR
            // one, so it does not outlive it.
            $table->foreignUuid('route_id')->constrained('routes')->cascadeOnDelete();

            // The passenger. Cascade for the same reason: the request is that
            // member's asking, not a fact about them that survives them.
            $table->foreignUuid('account_id')->constrained('accounts')->cascadeOnDelete();

            $table->string('status', 16);

            // The domain time, kept separate from `created_at` the way `routes`
            // keeps `published_at`. The row's timestamp is when the database
            // learned about the asking; this is when the asking happened.
            $table->timestampTz('requested_at');

            // Set by accept and decline only, and never both a decision and a
            // withdrawal — the check constraints below make that structural.
            $table->timestampTz('decided_at')->nullable();

            $table->timestampTz('withdrawn_at')->nullable();

            $table->timestampsTz();

            // One asking per member per route, at the only layer that can
            // guarantee it. Two concurrent requests with different client ids
            // both pass any application check; only this stops the second row.
            $table->unique(['route_id', 'account_id'], 'seat_requests_one_per_route_per_member');

            // The driver's per-route keyset: newest first, within one journey.
            $table->index(['route_id', 'created_at', 'id']);

            // The passenger's own keyset, across every journey they asked about.
            $table->index(['account_id', 'created_at', 'id']);
        });

        // Blueprint cannot express these, and an application enum is not a
        // database guarantee: a console command or a hand-written UPDATE would
        // bypass it. Same reasoning as the `routes` constraints.
        DB::statement(
            "alter table seat_requests add constraint seat_requests_status_check
             check (status in ('pending', 'accepted', 'declined', 'withdrawn'))"
        );

        // A decided request carries its decision time, and an undecided one
        // must not. Without this a row could claim to be `pending` while
        // holding the timestamp of a decision somebody made.
        DB::statement(
            "alter table seat_requests add constraint seat_requests_decision_shape_check
             check ((status in ('accepted', 'declined')) = (decided_at is not null))"
        );

        // The mirror of the same rule for withdrawal. Together the two make the
        // three terminal states mutually exclusive in the data, not merely in
        // the code that writes it: nothing can be both decided and withdrawn.
        DB::statement(
            "alter table seat_requests add constraint seat_requests_withdrawal_shape_check
             check ((status = 'withdrawn') = (withdrawn_at is not null))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('seat_requests');
    }
};
