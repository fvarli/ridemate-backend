<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One row per credential GENERATION. Append-only.
 *
 * THIS TABLE'S SHAPE IS THE SECURITY PROPERTY
 *
 * The obvious design keeps one refresh hash on the session and overwrites it
 * on every rotation. It cannot detect reuse, and it fails silently: once the
 * hash is overwritten, an attacker presenting the previous refresh token looks
 * exactly like an attacker presenting a random string. Both are "not found".
 * The one signal that a token was stolen is the one that gets erased.
 *
 * So rotation INSERTS. Generation N's row survives — its hash intact and its
 * `rotated_at` set — after N+1..N+k exist. Presenting N later resolves by
 * primary key, matches the hash, and finds `rotated_at` already set. That is
 * the detection, and because history is kept rather than overwritten it works
 * for ANY retained past generation, not merely the previous one.
 *
 * The consequence is that retention is a SECURITY parameter. Reuse is
 * detectable exactly as long as the row exists, so pruning these rows early
 * silently narrows the detection window. See config('ridemate.auth
 * .token_retention_tail') and the test that pins it.
 *
 * ACCESS AND REFRESH SHARE A ROW BECAUSE ONE ACT MINTS BOTH
 *
 * They do not share a lifetime. The access token dies at
 * `access_expires_at` — 15 minutes — and is deliberately still honoured after
 * its generation has been rotated, so a proactive refresh does not 401 every
 * request already in flight on the device. Revocation still takes effect
 * instantly, because it lives on the session and every validation joins it.
 *
 * Only hashes are stored. The plaintext exists in the response body and in the
 * client's storage, and nowhere else, ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auth_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();

            $table->foreignUuid('session_id')
                ->constrained('auth_sessions')
                ->cascadeOnDelete();

            // 1, 2, 3... within a session. Not load-bearing for validation —
            // lookup is by primary key — but it makes the chain legible when
            // reading rows during an incident, and the unique constraint below
            // turns "two rotations produced the same generation" into an error
            // instead of a puzzle.
            $table->unsignedInteger('generation');

            // SHA-256 hex. Not bcrypt: a lookup credential has to be findable,
            // and bcrypt's per-row salt makes finding it by hash impossible.
            // The entropy is 256 bits of random_bytes rather than a password,
            // so there is nothing for a slow hash to defend.
            $table->char('access_token_hash', 64);
            $table->timestampTz('access_expires_at');

            $table->char('refresh_token_hash', 64);
            $table->timestampTz('refresh_expires_at');

            // Set once, when this generation is exchanged. Its presence IS the
            // reuse signal.
            $table->timestampTz('rotated_at')->nullable();

            // The generation this one was exchanged for. Not needed to detect
            // reuse; kept because during an incident the question is always
            // "what happened next", and reconstructing the chain from
            // timestamps is guesswork.
            $table->uuid('succeeded_by_id')->nullable();

            $table->timestampTz('created_at');

            $table->unique(['session_id', 'generation']);
            $table->index('session_id');
        });

        // Declared separately, and it has to be. Inside the create closure
        // Laravel emits the foreign key before the primary key it references
        // exists, and PostgreSQL refuses: "no unique constraint matching given
        // keys for referenced table auth_tokens". A self-reference is the one
        // case where the table must exist before the constraint is added.
        Schema::table('auth_tokens', function (Blueprint $table): void {
            $table->foreign('succeeded_by_id')
                ->references('id')->on('auth_tokens')
                ->nullOnDelete();
        });

        // A successor implies a rotation. The reverse is not asserted: a row
        // may be rotated without a successor, which is what a future
        // "rotate and revoke" path would look like.
        DB::statement(
            'alter table auth_tokens add constraint auth_tokens_succession_check
             check (succeeded_by_id is null or rotated_at is not null)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_tokens');
    }
};
