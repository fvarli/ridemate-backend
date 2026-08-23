<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuthSession;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * PHP and PostgreSQL must agree about what "now" means.
 *
 * This is here because they did not, and nothing said so.
 *
 * Eloquent writes datetimes as naive strings with no offset. PostgreSQL
 * resolves a naive string against the session time zone, which defaults to the
 * server's — Europe/Istanbul on the development machine. Every timestamptz
 * written by the application was therefore landing three hours from where it
 * was meant to, and reading back three hours in the past.
 *
 * It surfaced as access tokens that were expired the instant they were issued.
 * It could equally have surfaced as credentials that outlived their configured
 * lifetime, on a server behind UTC — the same bug, in the direction nobody
 * notices until it matters.
 *
 * A configuration key alone would not survive a future edit to
 * config/database.php or a managed database with its own default, so the
 * behaviour is asserted rather than the setting.
 */
final class DatabaseTimezoneTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    public function test_the_connection_runs_in_utc(): void
    {
        self::assertSame('UTC', DB::selectOne('show timezone')->TimeZone);
        self::assertSame('UTC', config('app.timezone'));
    }

    /**
     * The assertion that actually matters: a written instant reads back as the
     * same instant, to the second.
     */
    public function test_a_timestamp_survives_a_round_trip_unshifted(): void
    {
        $account = $this->createAccount();
        $expiry = CarbonImmutable::now()->addDays(90);

        $session = new AuthSession;
        $session->account_id = $account->id;
        $session->absolute_expires_at = $expiry;
        $session->save();

        self::assertSame(
            $expiry->getTimestamp(),
            $session->refresh()->absolute_expires_at->getTimestamp(),
            'the stored instant was shifted by the database session time zone',
        );
    }

    /**
     * The symptom, pinned directly: something scheduled for the future must
     * not read back as past.
     */
    public function test_a_future_expiry_is_still_in_the_future_after_storage(): void
    {
        $account = $this->createAccount();

        $session = new AuthSession;
        $session->account_id = $account->id;
        $session->absolute_expires_at = CarbonImmutable::now()->addMinutes(15);
        $session->save();

        self::assertTrue($session->refresh()->absolute_expires_at->isFuture());
    }
}
