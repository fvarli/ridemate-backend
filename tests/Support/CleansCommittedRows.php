<?php

declare(strict_types=1);

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Leaves the database as empty as it was found.
 *
 * WHY THIS IS NEEDED AT ALL
 *
 * Most tests use RefreshDatabase, which wraps each one in a transaction and
 * rolls it back — cheap, and it assumes it starts from an empty database. A
 * few tests cannot use it, because they exercise behaviour that only exists
 * with a real commit: post-commit passcode dispatch, the cooldown surviving a
 * failed delivery, row locks held across two connections.
 *
 * Those tests COMMIT. DatabaseTruncation clears the tables before each of
 * them, but nothing clears up after the last one, so their rows outlive the
 * class — and the next RefreshDatabase test opens its transaction on a
 * database that already holds a passcode challenge for the number it was about
 * to use.
 *
 * The symptom is a suite that passes class by class and fails as a whole, with
 * the failure landing on whichever innocent test runs next. It is also
 * order-dependent, so adding a file breaks something unrelated.
 *
 * WHY tearDown AND NOT #[After]
 *
 * #[After] would compose more politely with classes that define their own
 * tearDown, but PHPUnit runs it after Laravel has already destroyed the
 * application — so the DB facade has no container to resolve from. The
 * truncation has to happen while the application is still alive, which means
 * before parent::tearDown().
 */
trait CleansCommittedRows
{
    protected function truncateCommittedAuthRows(): void
    {
        // One statement, CASCADE for the foreign keys. spatial_ref_sys and
        // migrations are never named: emptying the first leaves PostGIS
        // registered and broken.
        DB::statement('truncate table auth_tokens, auth_sessions, accounts, otp_challenges cascade');
    }
}
