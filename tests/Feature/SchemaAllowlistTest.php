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
 * quietly alongside a feature. Every table below was argued for in the phase
 * that added it; one appearing here without an argument is the failure this
 * catches.
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
        'cache',
        'cache_locks',
        'migrations',
        'otp_challenges',
        'places',
        'profiles',
        'reviews',
        'routes',
        'seat_requests',
        'spatial_ref_sys',
        'trips',
    ];

    /**
     * The columns `reviews` must NOT have.
     *
     * Every one of them is a fact another row already carries, and storing a
     * second copy is how the two start to disagree. `reviewer_account_id` and
     * `reviewee_account_id` in particular would make an impossible pairing
     * representable — the schema's safety rests on their absence, not on the
     * code that would have filled them. See the migration.
     *
     * @var list<string>
     */
    private const FORBIDDEN_REVIEW_COLUMNS = [
        'reviewer_account_id',
        'reviewee_account_id',
        'account_id',
        'route_id',
        'trip_id',
        'submitted_at',
        'counterpart_reviewed_at',
        'released_at',
        'release_at',
        'is_released',
        'text',
        'body',
        'comment',
        'tags',
        'average',
        'rating_count',
        'trust_score',
        'moderated_at',
        'reported_at',
        'deleted_at',
    ];

    /**
     * CARRIES WEIGHT. A review is five columns and a pair of timestamps.
     */
    public function test_reviews_holds_nothing_it_can_derive(): void
    {
        /** @var list<string> $columns */
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'reviews')
            ->orderBy('column_name')
            ->pluck('column_name')
            ->all();

        self::assertSame([
            'created_at',
            'id',
            'rating',
            'reviewer_role',
            'seat_request_id',
            'updated_at',
        ], $columns);

        foreach (self::FORBIDDEN_REVIEW_COLUMNS as $forbidden) {
            self::assertNotContains($forbidden, $columns, $forbidden);
        }
    }

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
            // Deferred: no capture flow exists to fill them. `profiles` left
            // this list in Phase 11, which built the capture flow.
            'consents', 'verifications', 'devices',
            // Deferred: no ops surface reads them, and the auth tables carry
            // their own timestamps.
            'audit_events', 'idempotency_records',
            // Product domain, later phases. `seat_requests` left this list in
            // Phase 13, which built the asking, `trips` in Phase 14, which
            // records whether the journey was made, and `reviews` in Phase 15,
            // which records what one party says about the other.
            // `route_occurrences` still has not: every phase so far supports
            // one-off routes only, so nothing per-day reads an occurrence yet.
            'vehicles', 'route_occurrences',
            'conversations', 'messages', 'trusted_contacts',
            'safety_incidents', 'blocks', 'reports', 'notifications',
            // Deferred: nothing is queued, so nothing needs a queue table.
            'jobs', 'failed_jobs',
            // Deferred: no web session exists, and SESSION_DRIVER stays file.
            'sessions',
        ] as $deferred) {
            self::assertFalse(
                Schema::hasTable($deferred),
                "$deferred was created without a decision",
            );
        }
    }
}
