<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Issued passcodes.
 *
 * Keyed by phone number and NOT by account, deliberately. A challenge is
 * created before anyone knows whether an account exists — that is what makes
 * `POST /auth/otp` unable to reveal it — and the account is resolved only on
 * successful verification.
 *
 * These rows are also the rate limiter. Per-phone issuance limits are counted
 * from them rather than from a cache counter, which makes the limit exact,
 * durable across restarts, and inspectable during an incident.
 *
 * ON THE PARTIAL UNIQUE INDEX
 *
 * At most one UNRESOLVED challenge may exist per phone. The predicate cannot
 * mention expiry, because `now()` is not IMMUTABLE and PostgreSQL will not
 * index on it. So "unresolved" means neither consumed nor invalidated, and the
 * issuing transaction invalidates every predecessor — expired ones included —
 * before inserting. Otherwise an expired row nobody has pruned yet would block
 * a member from ever getting another passcode, which is a lockout caused
 * entirely by housekeeping.
 *
 * The index is the invariant. The advisory lock in OtpService is the
 * coordination that keeps callers from meeting it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('otp_challenges', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->string('phone_e164', 20);

            // HMAC-SHA256, keyed and domain-separated, over the phone AND the
            // code. A bare digest of six digits is a 10^6 offline search that
            // finishes instantly; the key is what makes a leaked table useless
            // without also leaking APP_KEY. Binding the phone in means a hash
            // lifted from one row cannot validate against another.
            $table->char('code_hash', 64);

            $table->timestampTz('expires_at');

            // Bounded guessing. Five attempts inside five minutes is what keeps
            // a six-digit code safe; raising either without raising length
            // weakens the credential.
            $table->unsignedSmallInteger('attempts')->default(0);

            // Two different endings. `consumed_at` means it worked;
            // `invalidated_at` means it was superseded. Both make the row
            // resolved, and keeping them apart is what lets an incident
            // distinguish "the member signed in" from "they asked again".
            $table->timestampTz('consumed_at')->nullable();
            $table->timestampTz('invalidated_at')->nullable();

            $table->timestampTz('created_at');

            // Serves the cooldown and hourly-cap queries, newest first.
            $table->index(['phone_e164', 'created_at']);
        });

        DB::statement(
            'create unique index otp_challenges_one_unresolved_per_phone
             on otp_challenges (phone_e164)
             where consumed_at is null and invalidated_at is null'
        );

        // A challenge cannot both have worked and have been superseded.
        DB::statement(
            'alter table otp_challenges add constraint otp_challenges_resolution_check
             check (consumed_at is null or invalidated_at is null)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('otp_challenges');
    }
};
