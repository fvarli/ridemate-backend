<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One logical authenticated device, and the revocation boundary for it.
 *
 * A session is the TOKEN FAMILY. Every access token and every refresh
 * generation belongs to exactly one row here, so revocation is a single UPDATE
 * on this table rather than a walk through a token chain. There is deliberately
 * no `family_id` column anywhere: the family already has an identity, and a
 * second one would be a second thing to keep consistent.
 *
 * The device columns are whatever the client said it was. They are shown back
 * to the member some day, and to nobody else, and nothing may authorise on
 * them — a client can claim anything. They are nullable because a client that
 * sends nothing must still be able to sign in.
 *
 * `absolute_expires_at` is the ceiling that rotation cannot lift. Without it a
 * refresh token that keeps being exchanged grants an unbounded session, which
 * means a device compromised once stays compromised until someone notices.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            // Cascade: an account's sessions are meaningless without it. There
            // is no deletion flow yet, so this is the correct answer waiting
            // rather than a behaviour anything currently triggers.
            $table->foreignUuid('account_id')
                ->constrained('accounts')
                ->cascadeOnDelete();

            $table->string('device_name', 64)->nullable();
            $table->string('platform', 32)->nullable();
            $table->string('app_version', 32)->nullable();

            $table->timestampTz('absolute_expires_at');

            $table->timestampTz('revoked_at')->nullable();
            $table->string('revoked_reason', 32)->nullable();

            $table->timestampTz('created_at');
        });

        // The only index the hot path needs: "this account's live sessions".
        // Partial, because revoked rows are retained for reuse detection and
        // would otherwise dominate the index for no reader.
        DB::statement(
            'create index auth_sessions_account_live_index
             on auth_sessions (account_id) where revoked_at is null'
        );

        // Reason and revocation travel together in both directions. A reason
        // without a timestamp is a session that claims to be revoked and is
        // not; a revocation without a reason is an incident with no record of
        // why, which is precisely the field that matters when a member asks
        // why they were signed out.
        DB::statement(
            "alter table auth_sessions add constraint auth_sessions_revocation_check
             check (
                 (revoked_at is null and revoked_reason is null)
                 or (revoked_at is not null and revoked_reason in
                     ('logout', 'reuse_detected', 'account_suspended', 'operator'))
             )"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_sessions');
    }
};
