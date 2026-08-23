<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * No table exists without a decision.
 *
 * This is the descendant of Phase 8's "the schema contains no product table"
 * guard. That assertion was only ever a special case of this one — an
 * allowlist that happened to be empty — and it stopped being true the moment
 * accounts existed.
 *
 * The point is not to inventory the database. It is that adding a table
 * requires editing an explicit list, so speculative schema cannot arrive
 * quietly alongside a feature. Every table below was argued for in the Phase 9
 * plan; a seventh appearing here without one is the failure this catches.
 *
 * The rejected list is kept for the same reason it was kept in Phase 8:
 * naming what is deliberately absent is stronger than a bare equality
 * assertion, because it says WHY the set is closed.
 */
final class SchemaAllowlistTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Tables RideMate is allowed to have, and nothing else.
     *
     * `migrations` is Laravel's own ledger. `spatial_ref_sys` belongs to
     * PostGIS. Neither is RideMate domain state, and neither may be dropped.
     *
     * @var list<string>
     */
    private const ALLOWED = [
        'accounts',
        'auth_sessions',
        'auth_tokens',
        'migrations',
        'spatial_ref_sys',
    ];

    public function test_the_schema_contains_only_allowed_tables(): void
    {
        /** @var list<string> $tables */
        $tables = DB::table('pg_tables')
            ->where('schemaname', 'public')
            ->orderBy('tablename')
            ->pluck('tablename')
            ->all();

        self::assertSame(self::ALLOWED, $tables);
    }

    /**
     * The things Phase 9 decided NOT to build, named so the decision is visible.
     *
     * Several of these are one careless `make:model -m` away. `users` and
     * `personal_access_tokens` in particular are what a framework default or a
     * Sanctum install would create, and both were rejected with reasons that
     * are worth more than the tables would have been.
     */
    public function test_deferred_tables_were_not_created(): void
    {
        foreach ([
            // Rejected in favour of `accounts`: credentials and public
            // presentation have different audiences.
            'users',
            // Rejected with Sanctum itself. RideMate mints its own opaque
            // credentials because rotation and reuse detection had to be
            // written either way.
            'personal_access_tokens',
            // Deferred: no capture flow exists to fill them.
            'profiles', 'consents', 'verifications', 'devices',
            // Deferred: no ops surface reads them, and the auth tables carry
            // their own timestamps.
            'audit_events', 'idempotency_records',
            // Product domain, later phases.
            'vehicles', 'routes', 'route_occurrences', 'seat_requests', 'trips',
            'conversations', 'messages', 'reviews', 'trusted_contacts',
            'safety_incidents', 'blocks', 'reports', 'notifications',
            // Deferred: nothing is queued, so nothing needs a queue table.
            'jobs', 'failed_jobs',
        ] as $deferred) {
            self::assertFalse(
                Schema::hasTable($deferred),
                "$deferred was created without a decision",
            );
        }
    }
}
