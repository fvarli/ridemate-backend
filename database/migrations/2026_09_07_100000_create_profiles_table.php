<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A member's public identity.
 *
 * WHY THIS IS NOT A COLUMN ON `accounts`
 *
 * An account is a credential: a verified phone number and whether it may sign
 * in. A profile is what other members will eventually see. They have different
 * audiences, different lifetimes and different privacy rules — a display name
 * is shown to strangers, a phone number never is — so putting a name on the
 * credential row would make every account read a step away from leaking one.
 * The split is the same one `SchemaAllowlistTest` records for rejecting
 * `users`, applied a phase later.
 *
 * EXACTLY ONE PROFILE PER ACCOUNT, ENFORCED HERE
 *
 * `account_id` is unique. That is deliberate rather than incidental: making it
 * a database constraint means two concurrent first-time writes cannot produce
 * two profiles, whatever the application does. A check in a controller would
 * be a check two requests can both pass.
 *
 * WHY THERE IS NO `initials` COLUMN
 *
 * Initials are deterministic derived state — first character of the first
 * token, plus first of the last when there is more than one — so storing them
 * would create a second copy of the name that can disagree with the first. A
 * profile rendering `İY` beside `Ayşe Demir` is worse than no initials at all.
 * They are derived by App\Profiles\DisplayName every time a representation is
 * built, and Phase 12 reuses that one implementation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profiles', function (Blueprint $table): void {
            // UUIDv7, application-generated, like every other identifier here.
            // Internal: it identifies the row for the database and for foreign
            // keys, and it is deliberately absent from every API response —
            // Phase 11 publishes no public profile id, because nothing in
            // Phase 11 or Phase 12 needs one.
            $table->uuid('id')->primary();

            // One profile per account, at the only layer that can guarantee it.
            // Cascade because a profile has no meaning without its account:
            // it is that account's presentation, not a record about it.
            $table->uuid('account_id')->unique();
            $table->foreign('account_id')
                ->references('id')
                ->on('accounts')
                ->cascadeOnDelete();

            // What the member calls themselves, as they typed it minus
            // surrounding whitespace. 80 characters is the contract's limit and
            // the column mirrors it, so an over-long name is a clean 422 rather
            // than a truncation nobody notices. Characters, not bytes: PostgreSQL
            // varchar(n) counts characters, which is what the validator counts too.
            $table->string('display_name', 80);

            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profiles');
    }
};
