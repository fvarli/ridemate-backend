<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An account gains somewhere to keep a proven email address. Nothing puts one
 * there.
 *
 * CAPABILITY IS NOT ACTIVATION, AND THE DIFFERENCE IS THE WHOLE SLICE
 *
 * A mature registration will eventually prove two possessions and produce an
 * account carrying both. This is the column that account will need, added on
 * its own so that the day completion ships it is not also altering `accounts`
 * in the middle of an authentication change — which is the worst time to alter
 * a table.
 *
 * Today no code writes either column. `AuthenticateByPhone` is untouched,
 * `POST /auth/otp/verify` is untouched, and no lookup anywhere resolves an
 * account by address. Adding a column is not adding a feature.
 *
 * EXISTING ROWS ARE NOT INCOMPLETE, AND NOTHING HERE SAYS THEY ARE
 *
 * Every account today was created by a verified phone number, which remains a
 * whole identity. Both columns are NULL for all of them, and that NULL is the
 * truth — this member has not proven an address — rather than a gap waiting to
 * be filled. There is no backfill, because there is nothing to fill it with: an
 * `email_verified_at` invented for a row whose owner never verified anything
 * would be the one thing a verification timestamp must never be. There is no
 * `registration_complete` flag either, and there must not be: it would mark
 * every existing member as defective for having signed up before the second
 * channel existed.
 *
 * `phone_e164` and `phone_verified_at` stay NOT NULL. That is true of every row
 * that exists and of every mature registration to come, and relaxing it is what
 * an email-only account would require — a rollout decision nobody has made.
 *
 * THE CHECK IS TWO-WAY HERE AND ONE-WAY ON `registrations`
 *
 * A registration binds a destination BEFORE proving it — that is what the
 * passcode is sent to — so there the implication runs one way: a timestamp
 * requires its identifier, and an identifier without one is the ordinary state
 * in between. An account has no such state. It never holds an address it has
 * not proven, so the two columns travel together in both directions and the
 * database says so.
 *
 * UNIQUENESS IS PARTIAL, THOUGH PostgreSQL WOULD NOT REQUIRE IT TO BE
 *
 * A plain unique index would already permit many NULLs, because PostgreSQL
 * treats them as distinct. `where email is not null` is written anyway: it says
 * the rule is about addresses rather than about rows, and it keeps the index
 * off every legacy account, which is all of them.
 *
 * The indexed value is whatever `App\Support\EmailAddress` produced —
 * lowercased in full, trimmed, and folded no further. Case-insensitive identity
 * therefore falls out of the stored form rather than out of a functional index,
 * which is what keeps one definition of "the same address" in the application
 * and none in the schema. No dot removal, no `+tag` stripping, no DNS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table): void {
            // 254 is RFC 5321's practical ceiling for a whole address, and the
            // cap App\Support\EmailAddress already enforces — the same width
            // `registrations.email` holds, so a proven address moves between
            // the two without a truncation nobody notices.
            $table->string('email', 254)->nullable();

            $table->timestampTz('email_verified_at')->nullable();
        });

        DB::statement(
            'create unique index accounts_email_unique
             on accounts (email)
             where email is not null'
        );

        DB::statement(
            'alter table accounts add constraint accounts_email_verification_check
             check (
                 (email is null and email_verified_at is null)
                 or (email is not null and email_verified_at is not null)
             )'
        );
    }

    /**
     * The reverse, in reverse order.
     *
     * Refuses rather than destroys if any account carries an address. Dropping
     * the column would discard a proof that was genuinely earned, and there is
     * no second copy of it anywhere — the registration it came from is
     * consumed, and expires.
     */
    public function down(): void
    {
        $withEmail = DB::table('accounts')->whereNotNull('email')->count();

        if ($withEmail > 0) {
            throw new RuntimeException(
                'accounts holds verified email addresses, which the previous phone-only '
                .'shape cannot represent. Dropping the column would discard proof that '
                .'exists nowhere else. Resolve them first.',
            );
        }

        DB::statement('alter table accounts drop constraint accounts_email_verification_check');
        DB::statement('drop index accounts_email_unique');

        Schema::table('accounts', function (Blueprint $table): void {
            $table->dropColumn(['email', 'email_verified_at']);
        });
    }
};
