<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A registration that has not produced an account yet.
 *
 * WHY THIS IS NOT AN ACCOUNT IN A PARTIAL STATE
 *
 * A mature registration proves two independent possessions — an email address
 * and a phone number — and neither alone entitles anyone to anything. The
 * tempting shortcut is to create the account on the first proof and mark it
 * incomplete, and it is the wrong shape twice over: `accounts` is the
 * authenticated principal, so a partially registered row there is a principal
 * the whole application has to remember not to trust, and `status` would grow
 * a third value whose only behaviour is "refuse everything". Proof accumulates
 * out here instead, and an account comes into existence already whole.
 *
 * WHY THE PROOFS ARE COLUMNS AND NOT A CHILD TABLE
 *
 * For the reason `accounts.phone_verified_at` is a column rather than a
 * `verifications` row: a child table would hold at most two rows per
 * registration, of two known kinds, in one state each. What a pair of nullable
 * columns buys on top of that is the invariant — "both proven" is a `NOT NULL`
 * test the database can see, where "two rows exist" is a COUNT no constraint
 * can express. It becomes a table if a third channel ever arrives, or if a
 * proof grows facts of its own worth keeping.
 *
 * DELIBERATELY ABSENT
 *
 * `account_id` — the account this registration produced is derivable from the
 * canonical phone number for exactly as long as a verified identifier cannot
 * change. It is a column the day one can, and not before.
 *
 * A step, a status or a completed flag — each is `completed_at` and the two
 * proof timestamps read back in a different vocabulary, and a second copy of a
 * fact is a second thing that can disagree with the first.
 *
 * A device, a locale, a display name, a challenge id — none of them is proof of
 * anything, and a name belongs to `profiles`, behind the boundary that keeps a
 * credential identity out of what other members are shown.
 *
 * NO UNIQUENESS ACROSS IN-FLIGHT REGISTRATIONS, AND THAT IS THE DECISION
 *
 * "One active registration per address" is the obvious index and it is a
 * lockout waiting to be shipped. The predicate could not mention `expires_at`,
 * because `now()` is not IMMUTABLE and PostgreSQL will not index on it — the
 * same wall `otp_challenges` hit — so "active" would degrade to "not
 * completed", and one abandoned attempt would bar an address until something
 * pruned it. `otp_challenges` pays for that with unconditional invalidation of
 * its predecessors; here the price buys nothing, because an unproven
 * registration confers nothing. Two people racing on one address is not a
 * conflict until one of them finishes, and `accounts` already refuses the
 * second — which is the layer where ownership is actually decided.
 *
 * NO INDEX ON `expires_at`
 *
 * Nothing reads it. A credential resolves by primary key, and the sweep that
 * would scan this column is the retention policy, which has not been decided
 * — see docs/architecture.md. An index whose only reader is a future phase is
 * the speculative schema `SchemaAllowlistTest` exists to keep out.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('registrations', function (Blueprint $table): void {
            // UUIDv7, application-generated, like every other identifier here.
            // Also the only value about a registration that is safe to log.
            $table->uuid('id')->primary();

            // SHA-256 hex of the credential's secret, under a hash domain of
            // its own. Not bcrypt, for the reason `auth_tokens` gives: a
            // lookup credential must be findable, and the entropy is 256 bits
            // of random_bytes rather than a password, so there is nothing for a
            // slow hash to defend.
            //
            // Unique because two registrations sharing a secret would make one
            // credential resolve two aggregates. It cannot happen by chance;
            // the constraint is what turns "it cannot" into "it did not".
            $table->char('credential_hash', 64)->unique();

            // Canonical, or absent. 254 is RFC 5321's practical ceiling and the
            // cap App\Support\EmailAddress already enforces; 20 is what
            // `accounts.phone_e164` holds, because E.164 caps at 15 digits plus
            // '+'. Nullable because a registration exists before either
            // destination has been named.
            $table->string('email', 254)->nullable();
            $table->string('phone_e164', 20)->nullable();

            // The proof. Set when a passcode delivered to that destination has
            // been verified for THIS registration, and never on the strength of
            // a challenge row someone else consumed.
            $table->timestampTz('email_verified_at')->nullable();
            $table->timestampTz('phone_verified_at')->nullable();

            // One clock for the aggregate and its credential. A second
            // expiry on the credential would be a second thing to keep in step
            // with this one, for no property this does not already have.
            $table->timestampTz('expires_at');

            // Consumption. Set when this registration has produced its account,
            // after which the credential may advance nothing — and, in
            // particular, may not mint another session. See
            // docs/architecture.md.
            $table->timestampTz('completed_at')->nullable();

            // No `updated_at`, as on `otp_challenges` and for the same reason:
            // every change worth knowing about already writes a timestamp that
            // says what changed, and a generic one would answer "something".
            $table->timestampTz('created_at');
        });

        // A verification timestamp without the identifier it verified is a row
        // claiming to have proven nothing in particular. One-way on purpose:
        // an identifier IS bound before it is proven — that is what the
        // challenge is sent to — so the reverse implication would forbid the
        // flow rather than describe it.
        DB::statement(
            'alter table registrations add constraint registrations_email_proof_check
             check (email_verified_at is null or email is not null)'
        );

        DB::statement(
            'alter table registrations add constraint registrations_phone_proof_check
             check (phone_verified_at is null or phone_e164 is not null)'
        );
    }

    /**
     * Drops only what this migration created. Nothing else is touched, and no
     * existing row anywhere is read, rewritten or deleted.
     */
    public function down(): void
    {
        Schema::dropIfExists('registrations');
    }
};
