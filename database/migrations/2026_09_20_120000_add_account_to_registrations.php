<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A completed registration says WHICH account it produced, and not merely that
 * it produced one.
 *
 * THIS SUPERSEDES THE REASONING IN `create_registrations_table`
 *
 * That migration left `account_id` out, on the ground that the account is
 * "derivable from the canonical phone number for exactly as long as a verified
 * identifier cannot change" — a column for the day one can, and not before.
 * The conclusion was wrong for a reason the argument never reached: the fact is
 * knowable only inside the completion transaction, and it cannot be backfilled.
 * Adding the column on the day identifiers become mutable would record
 * provenance for completions from that day forward and leave every earlier one
 * permanently unattributable. There is no second chance at a write-once fact.
 *
 * WHAT EACH COLUMN ANSWERS, AND WHY THAT IS TWO QUESTIONS
 *
 * `completed_at` answers "did completion happen?" — and it is what makes the
 * credential non-advanceable, which is unchanged here. `account_id` answers
 * "which exact account is the product of this registration?" Nothing else in
 * the schema answers the second. It is not a second copy of the first, and it
 * is not derivable from the identifiers: matching `registrations.email` or
 * `phone_e164` against `accounts` is an INFERENCE, sound only while identifiers
 * are immutable and never reused. Under an identifier-change flow the lookup
 * finds nothing; under recycling — which phone numbers genuinely are — it finds
 * a DIFFERENT account and answers confidently with the wrong one. A silently
 * wrong provenance answer is worse than none, and the column is what removes
 * the question.
 *
 * RESTRICT ON DELETE, AND WHY NEITHER ALTERNATIVE IS AVAILABLE
 *
 * `cascadeOnDelete` is what `otp_challenges.registration_id` uses, because a
 * challenge is meaningless without its registration. The direction here is the
 * opposite and so is the answer: a completed registration is a record of
 * something that happened, and deleting the account must not silently delete
 * the evidence of where it came from. `nullOnDelete` is worse still — it would
 * destroy exactly the one fact this column exists to hold while leaving a row
 * that still claims completion, which the CHECK below would then refuse.
 *
 * So RESTRICT: the correct answer waiting rather than a behaviour anything
 * triggers. No account deletion path exists — `AccountStatus` has two cases and
 * neither is deleted — so this refuses nothing today, and it forces the
 * question to be answered by whoever ships deletion rather than resolved by a
 * default nobody chose.
 *
 * IT DECIDES NOTHING ABOUT RETENTION, WHICH REMAINS `LEGAL REVIEW REQUIRED`
 *
 * How long an abandoned registration may be KEPT is unanswered and is not
 * answered here — see docs/architecture.md. A foreign key is not a retention
 * policy, and no duration may be inferred from it. Note that the constraint
 * only ever restricts deleting an ACCOUNT; a registration row remains as
 * deletable as it was, which is what a retention sweep would need.
 *
 * ONE REGISTRATION, ONE ACCOUNT, BOTH WAYS
 *
 * The unique index makes "an account is the product of at most one
 * registration" a guarantee rather than a property of the current code. It
 * cannot be violated today — completion only ever INSERTS a fresh account and
 * refuses every collision rather than adopting an existing row — which is
 * precisely the `credential_hash` argument: the constraint is what turns "it
 * cannot" into "it did not". A flow that attached an EXISTING account to a
 * registration would be adoption, not production, and is forbidden; it would
 * also need a different column, not this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('registrations', function (Blueprint $table): void {
            // Nullable because a registration exists, and may end unfinished,
            // without ever producing an account. NOT NULL would make the
            // ordinary in-flight state unrepresentable.
            $table->uuid('account_id')->nullable();

            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->restrictOnDelete();
        });

        // Partial for the reason `accounts_email_unique` is: PostgreSQL would
        // already treat the NULLs as distinct, and the predicate says the rule
        // is about accounts rather than about rows. It also keeps the index off
        // every in-flight registration, which is most of them, and it is the
        // only index this column needs — the foreign key creates none of its
        // own in PostgreSQL.
        DB::statement(
            'create unique index registrations_account_id_unique
             on registrations (account_id)
             where account_id is not null'
        );

        // Two-way, like `accounts_email_verification_check` and unlike the two
        // proof checks on this table. Those are one-way because an identifier
        // IS bound before it is proven; there is no such in-between state here.
        // A linkage without a completion is an account claimed by a
        // registration that never finished, and a completion without a linkage
        // is the provenance gap this migration exists to close — so the
        // database refuses to represent either.
        DB::statement(
            'alter table registrations add constraint registrations_account_completion_check
             check (
                 (account_id is null and completed_at is null)
                 or (account_id is not null and completed_at is not null)
             )'
        );
    }

    /**
     * The reverse, in reverse order.
     *
     * Refuses rather than destroys if any registration names its account.
     * Dropping the column would discard provenance that exists nowhere else and
     * that no later migration could reconstruct — which is the whole argument
     * for adding it. A migration that cannot go back safely should say so.
     */
    public function down(): void
    {
        $linked = DB::table('registrations')->whereNotNull('account_id')->count();

        if ($linked > 0) {
            throw new RuntimeException(
                'registrations holds completion provenance, which the previous shape cannot '
                .'represent. Dropping the column would discard the record of which account '
                .'each registration produced, and nothing else holds it. Resolve them first.',
            );
        }

        DB::statement('alter table registrations drop constraint registrations_account_completion_check');
        DB::statement('drop index registrations_account_id_unique');

        Schema::table('registrations', function (Blueprint $table): void {
            $table->dropForeign(['account_id']);
            $table->dropColumn('account_id');
        });
    }
};
