<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's cache tables, created because rate limiting now needs them.
 *
 * Phase 8 deliberately refused these: there was no route to throttle, so a
 * cache store and a cache table would have been placeholder infrastructure.
 * They arrive now with a consumer.
 *
 * WHY NOT THE FILE STORE, WHICH NEEDS NO TABLE
 *
 * Because it is not safe for this. Illuminate\Cache\FileStore::add() takes an
 * exclusive file lock, but FileStore::increment() is a bare read-modify-write
 * with no lock at all — and RateLimiter::increment() calls add() once and then
 * relies on the store's increment for every hit after the first. Parallel
 * requests therefore lose hits and more of them get through than the limit
 * allows, which is precisely the concurrency an attacker creates.
 *
 * DatabaseStore has the opposite shape: add() is an insertOrIgnore against the
 * primary key, and increment() runs inside a transaction with lockForUpdate().
 * On PostgreSQL that serializes, so the count is exact.
 *
 * WHY NOT REDIS
 *
 * Nothing here measures a need for it. Redis would be a second piece of
 * infrastructure to run, monitor and back up, in exchange for speed this
 * service does not yet need at a scale it does not yet have.
 *
 * The schema is Laravel's own cache stub verbatim. It is framework state
 * rather than RideMate domain state, and diverging from it would only create
 * a difference for a future upgrade to trip over.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });

        Schema::create('cache_locks', function (Blueprint $table): void {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');
    }
};
