<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A live challenge stops being identified by its destination alone.
 *
 * WHAT WAS WRONG, AND IT WAS NOT REACHABILITY
 *
 * `otp_challenges_one_unresolved_per_destination` said: at most one unresolved
 * challenge per `(channel, destination)`. That was the correct identity while
 * every challenge meant one thing — prove this number, sign this person in.
 *
 * Pre-account registration made it false. Several registrations may name one
 * address at the same time, on purpose: nothing is unique across in-flight
 * registrations, because a uniqueness rule there would let anyone bar an
 * address they do not own. So a code delivered for one registration verified
 * another that had merely bound the same address, and — worse — a registration
 * code could be presented at `POST /auth/otp/verify`, where the destination
 * matched and nothing else was being asked, and open an account.
 *
 * Ordering the writes so that no caller currently does either was not a fix.
 * This table is where the identity lives, so this is where it is corrected.
 *
 * WHY A NULLABLE COLUMN AND TWO PARTIAL INDEXES
 *
 * Existing rows are standalone challenges, because that is what they are: every
 * one was issued by the sign-in path or the internal email capability, neither
 * of which knows what a registration is. NULL is that fact rather than a
 * placeholder, and it is what keeps `POST /auth/otp` byte-for-byte unchanged —
 * its index is the old one with `registration_id is null` added, and for a
 * table whose rows are all NULL that is the same index.
 *
 * Registration challenges get their own rule, keyed on
 * `(registration_id, channel)`. The destination is not in that key because it
 * cannot vary: a registration binds one destination per channel, once, for
 * ever. Adding it would suggest a second one could exist.
 *
 * Neither predicate mentions expiry, for the reason the original could not:
 * `now()` is not IMMUTABLE and PostgreSQL will not index on it. "Unresolved"
 * therefore still means neither consumed nor invalidated, and issuance still
 * invalidates its predecessors unconditionally — now within its own scope only,
 * so one registration's resend cannot kill another's live challenge, and
 * neither can touch a sign-in.
 *
 * ON DELETE CASCADE, AND WHY NOT `nullOnDelete`
 *
 * A registration's challenges are meaningless without it. `nullOnDelete` would
 * be a privilege escalation with a tidy name: deleting a registration would
 * PROMOTE its unresolved challenge into the standalone namespace, where a code
 * that proved an address to a registration becomes a code that signs somebody
 * in. There is no deletion flow yet, so this is the correct answer waiting
 * rather than a behaviour anything triggers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->uuid('registration_id')->nullable();

            $table->foreign('registration_id')
                ->references('id')
                ->on('registrations')
                ->cascadeOnDelete();
        });

        // Dropped before its replacements exist, because the narrower one below
        // is the same rule plus a predicate: keeping both for a moment would
        // mean two indexes asserting the same thing about the same rows.
        DB::statement('drop index otp_challenges_one_unresolved_per_destination');

        DB::statement(
            'create unique index otp_challenges_one_unresolved_standalone_per_destination
             on otp_challenges (channel, destination)
             where registration_id is null
               and consumed_at is null
               and invalidated_at is null'
        );

        DB::statement(
            'create unique index otp_challenges_one_unresolved_per_registration_channel
             on otp_challenges (registration_id, channel)
             where registration_id is not null
               and consumed_at is null
               and invalidated_at is null'
        );
    }

    /**
     * The reverse, in reverse order.
     *
     * Refuses rather than destroys if the table holds registration-scoped rows.
     * Rolling back with them present would mean either deleting them or
     * collapsing them into the destination namespace — and the second is the
     * exact escalation this migration exists to prevent. A migration that
     * cannot go back safely should say so.
     */
    public function down(): void
    {
        $scoped = DB::table('otp_challenges')->whereNotNull('registration_id')->count();

        if ($scoped > 0) {
            throw new RuntimeException(
                'otp_challenges holds registration-scoped challenges, which the previous '
                .'destination-only shape cannot represent without promoting them into the '
                .'sign-in namespace. Resolve or remove them first.',
            );
        }

        DB::statement('drop index otp_challenges_one_unresolved_per_registration_channel');
        DB::statement('drop index otp_challenges_one_unresolved_standalone_per_destination');

        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->dropForeign(['registration_id']);
            $table->dropColumn('registration_id');
        });

        DB::statement(
            'create unique index otp_challenges_one_unresolved_per_destination
             on otp_challenges (channel, destination)
             where consumed_at is null and invalidated_at is null'
        );
    }
};
