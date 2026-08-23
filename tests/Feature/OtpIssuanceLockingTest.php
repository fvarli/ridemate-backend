<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The advisory lock genuinely serializes issuance per phone number.
 *
 * OtpServiceTest proves the POLICY — cooldowns, caps, one unresolved challenge.
 * What it cannot show is that two simultaneous requests for the same number
 * cannot interleave their policy checks with each other's insert, because both
 * of its calls run one after the other on a single connection where the lock is
 * never contended.
 *
 * Without the lock the failure is not theoretical: two requests both read "no
 * recent challenge", both pass the cooldown, and one then hits the partial
 * unique index and errors. The index would hold — no duplicate row — but the
 * member would see a 500 instead of a passcode.
 *
 * Same shape as RefreshLockingTest, and for the same reasons: no
 * RefreshDatabase (a single wrapping transaction cannot contend with itself)
 * and a lock_timeout, because the failure mode of a lock test is a hang.
 */
final class OtpIssuanceLockingTest extends TestCase
{
    private const SECOND = 'rm_otp_concurrent';

    /** Mirrors OtpService::lockKey. Duplicated on purpose — see the test. */
    private static function lockKey(string $phone): int
    {
        $unpacked = unpack('J', substr(hash('sha256', 'rm.otp.lock.v1:'.$phone, true), 0, 8));
        self::assertIsArray($unpacked);

        return (int) $unpacked[1];
    }

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        config(['database.connections.'.self::SECOND => config('database.connections.pgsql')]);
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::SECOND);

        parent::tearDown();
    }

    /**
     * Whether a second connection can take the lock, without waiting for it.
     *
     * pg_try_advisory_xact_lock returns instead of blocking, which makes the
     * assertion a plain boolean rather than a timeout race — deterministic,
     * fast, and it cannot hang CI.
     */
    private function otherConnectionCanLock(int $key): bool
    {
        $second = DB::connection(self::SECOND);
        $second->beginTransaction();

        try {
            $row = $second->selectOne('select pg_try_advisory_xact_lock(?) as taken', [$key]);

            return (bool) $row->taken;
        } finally {
            $second->rollBack();
        }
    }

    public function test_two_transactions_cannot_hold_one_number_at_once(): void
    {
        $key = self::lockKey('+905321234567');

        // Free before anyone holds it, so a false below means "held by the
        // other transaction" rather than "this key never works".
        self::assertTrue($this->otherConnectionCanLock($key));

        DB::beginTransaction();

        try {
            DB::select('select pg_advisory_xact_lock(?)', [$key]);

            self::assertFalse(
                $this->otherConnectionCanLock($key),
                'a second transaction took a lock that was already held',
            );
        } finally {
            DB::rollBack();
        }

        // And it is released with the transaction, so the next request for
        // this number is delayed rather than locked out.
        self::assertTrue($this->otherConnectionCanLock($key));
    }

    /**
     * Different members must not queue behind each other.
     *
     * This is the reason for a 64-bit key rather than PostgreSQL's hashtext(),
     * whose 32 bits make a collision likely at roughly seventy thousand
     * distinct numbers. A collision would not corrupt anything — the unique
     * index still holds — but two strangers would serialize against each other
     * for no reason, and the cause would be invisible.
     */
    public function test_different_numbers_do_not_block_each_other(): void
    {
        DB::beginTransaction();

        try {
            DB::select('select pg_advisory_xact_lock(?)', [self::lockKey('+905321234567')]);

            self::assertTrue(
                $this->otherConnectionCanLock(self::lockKey('+905329876543')),
                'an unrelated number was blocked by this one',
            );
        } finally {
            DB::rollBack();
        }
    }

    /**
     * The key is a signed 64-bit integer, which is the domain
     * pg_advisory_xact_lock accepts — including negative values, which a
     * high-bit digest produces roughly half the time.
     */
    public function test_the_key_is_deterministic_and_spans_the_signed_range(): void
    {
        self::assertSame(self::lockKey('+905321234567'), self::lockKey('+905321234567'));
        self::assertNotSame(self::lockKey('+905321234567'), self::lockKey('+905329876543'));

        // A digest whose leading bit is set becomes a negative PHP int. The
        // database must accept it, so it is round-tripped rather than assumed.
        $negative = self::lockKey('+905329876543');
        self::assertLessThan(0, $negative);

        DB::beginTransaction();
        DB::select('select pg_advisory_xact_lock(?)', [$negative]);
        DB::rollBack();
    }
}
