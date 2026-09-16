<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A challenge becomes a delivered code to a destination, rather than a passcode
 * for a phone number.
 *
 * WHAT CHANGES, AND WHAT DELIBERATELY DOES NOT
 *
 * The table already held everything a one-time passcode needs — a keyed hash,
 * an expiry, an attempt counter, and two distinguishable endings. None of that
 * is phone-specific and none of it moves. What was phone-specific was the
 * NAME of one column and the two indexes built on it.
 *
 * So this renames `phone_e164` to `destination`, adds the channel that says how
 * to read it, and rebuilds the indexes around the pair. The hash, the check
 * constraint and every timestamp are untouched.
 *
 * EXISTING ROWS ARE SMS CHALLENGES, BECAUSE THAT IS WHAT THEY ARE
 *
 * Every row in this table was issued by the phone flow, which is the only flow
 * there has ever been. `default 'sms'` backfills them truthfully rather than
 * guessing, and the default is then dropped: after this migration a writer must
 * say which channel it meant, because the day two channels exist an omitted one
 * would silently become SMS.
 *
 * THE PARTIAL UNIQUE INDEX IS THE POINT OF THE WHOLE SLICE
 *
 * It was "one unresolved challenge per phone". It becomes "one unresolved
 * challenge per channel per destination" — so a member with an email challenge
 * in flight can still be sent an SMS one, and neither invalidates the other.
 * Widened to the destination alone it would have coupled the two channels
 * together at exactly the moment the product needs them independent.
 *
 * WIDENED TO 255
 *
 * E.164 caps at 15 digits and the column was 20. An email address does not, and
 * a column that could not hold one would mean a second migration in the slice
 * that introduces email — during an authentication change, which is the worst
 * time to alter a table. The width is not a claim that email works yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('otp_challenges', function (Blueprint $table): void {
            // Default-backfilled, so existing rows become what they already
            // are. Dropped below so future writes must be explicit.
            $table->string('channel', 8)->default('sms');
        });

        DB::statement('alter table otp_challenges alter column channel drop default');

        // The old indexes name a column that is about to stop existing.
        // Dropped before the rename so neither is left describing a shape
        // nothing queries any more.
        DB::statement('drop index otp_challenges_one_unresolved_per_phone');

        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->dropIndex(['phone_e164', 'created_at']);
        });

        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->renameColumn('phone_e164', 'destination');
        });

        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->string('destination', 255)->change();
        });

        // Serves both policy reads — the newest challenge for this identity,
        // and how many were issued within the hour — and both are now scoped
        // by channel, so the channel leads.
        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->index(['channel', 'destination', 'created_at']);
        });

        DB::statement(
            'create unique index otp_challenges_one_unresolved_per_destination
             on otp_challenges (channel, destination)
             where consumed_at is null and invalidated_at is null'
        );
    }

    /**
     * The reverse, in reverse order.
     *
     * Refuses rather than destroys if the table holds a channel this shape
     * cannot represent: rolling back with email challenges present would mean
     * either deleting them or calling them SMS, and both are worse than
     * stopping. A migration that cannot go back safely should say so.
     */
    public function down(): void
    {
        $foreign = DB::table('otp_challenges')->where('channel', '!=', 'sms')->count();

        if ($foreign > 0) {
            throw new RuntimeException(
                'otp_challenges holds non-SMS challenges, which the previous '
                .'phone-only shape cannot represent. Resolve or remove them first.',
            );
        }

        DB::statement('drop index otp_challenges_one_unresolved_per_destination');

        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->dropIndex(['channel', 'destination', 'created_at']);
        });

        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->renameColumn('destination', 'phone_e164');
        });

        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->string('phone_e164', 20)->change();
            $table->dropColumn('channel');
        });

        Schema::table('otp_challenges', function (Blueprint $table): void {
            $table->index(['phone_e164', 'created_at']);
        });

        DB::statement(
            'create unique index otp_challenges_one_unresolved_per_phone
             on otp_challenges (phone_e164)
             where consumed_at is null and invalidated_at is null'
        );
    }
};
