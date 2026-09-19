<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\DeviceDescription;
use App\Models\Account;
use App\Models\Registration;
use App\Otp\Email\EmailSender;
use App\Otp\Email\InMemoryEmailSender;
use App\Otp\OtpChannel;
use App\Otp\Sms\InMemorySmsSender;
use App\Otp\Sms\SmsSender;
use App\Registration\CompleteRegistration;
use App\Registration\RefusalReason;
use App\Registration\RegistrationCompletionRefused;
use App\Registration\RegistrationService;
use App\Registration\SendRegistrationPasscode;
use App\Registration\VerifyRegistrationPasscode;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CleansCommittedRows;
use Tests\TestCase;

/**
 * Completion really is serialized, and by the database rather than by this
 * application.
 *
 * `RegistrationCompletionTest` proves the behaviour — one account, preserved
 * timestamps, a refusal on every collision. What it cannot show is that two
 * SIMULTANEOUS attempts reach the same answer, because its calls run one after
 * the other on a single connection where no lock is ever contended.
 *
 * Three facts are asserted here, and each is a different arbiter.
 *
 * The first is the ROW LOCK. A completion blocks while another transaction
 * holds the registration row, so two attempts on one registration cannot both
 * read `completed_at IS NULL` and both insert an account.
 *
 * The second and third are the UNIQUE INDEXES. A completion claiming an email
 * address — or a number — that another, still-uncommitted transaction is
 * inserting blocks on the index and then refuses. This is the property that
 * would survive if every check in `CompleteRegistration` were deleted, and it
 * is the reason there is no "does an account already exist?" pre-check: a
 * pre-check would pass cleanly here and insert the second account.
 *
 * Same shape as `RegistrationProofLockingTest`, and for the same reasons: no
 * RefreshDatabase — a single wrapping transaction cannot contend with itself —
 * and a `lock_timeout`, because the failure mode of a lock test is a hang.
 */
final class RegistrationCompletionLockingTest extends TestCase
{
    use CleansCommittedRows;

    private const SECOND = 'rm_completion_concurrent';

    private const EMAIL = 'member@ridemate.invalid';

    private const PHONE = '+905321234567';

    private const OTHER_PHONE = '+905329876543';

    private InMemoryEmailSender $email;

    private InMemorySmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        config(['database.connections.'.self::SECOND => config('database.connections.pgsql')]);

        $this->truncateCommittedAuthRows();

        $this->email = new InMemoryEmailSender;
        $this->sms = new InMemorySmsSender;
        $this->app->instance(EmailSender::class, $this->email);
        $this->app->instance(SmsSender::class, $this->sms);
    }

    protected function tearDown(): void
    {
        DB::statement('set lock_timeout = 0');
        DB::disconnect(self::SECOND);
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    /**
     * CARRIES WEIGHT.
     *
     * The second connection holds the registration row. A completion on this
     * one must wait for it, and must not have inserted an account on the way.
     */
    public function test_completion_waits_on_the_registration_row(): void
    {
        $registration = $this->proven();

        $second = DB::connection(self::SECOND);
        $second->beginTransaction();

        try {
            $second->selectOne(
                'select id from registrations where id = ? for update',
                [$registration->id],
            );

            $blocked = $this->blocksOn(fn () => $this->complete($registration));

            self::assertTrue($blocked, 'completion did not wait for the registration row');
            self::assertSame(0, Account::query()->count(), 'an account was inserted before the row was held');
        } finally {
            $second->rollBack();
        }

        // And once the holder lets go, the same registration still completes.
        $this->complete(Registration::query()->findOrFail($registration->id));
        self::assertSame(1, Account::query()->count());
    }

    /**
     * CARRIES WEIGHT. REQUIRED CASE: same registration, completed concurrently.
     *
     * The winner takes the row, writes its account and its `completed_at`, and
     * commits. The loser — which the lock made wait rather than race — re-reads
     * the row it was blocked on and finds it finished, so it refuses instead of
     * inserting a second account.
     */
    public function test_a_concurrent_winner_leaves_the_loser_nothing_to_complete(): void
    {
        $registration = $this->proven();

        $second = DB::connection(self::SECOND);
        $second->beginTransaction();

        try {
            $second->selectOne('select id from registrations where id = ? for update', [$registration->id]);

            // While the row is held, this connection cannot get past the lock.
            self::assertTrue(
                $this->blocksOn(fn () => $this->complete($registration)),
                'completion did not wait for the registration row',
            );

            // The winner finishes: one account, and the registration consumed.
            $this->insertAccount($second, self::EMAIL, self::PHONE);
            $second->table('registrations')
                ->where('id', $registration->id)
                ->update(['completed_at' => CarbonImmutable::now()]);

            $second->commit();
        } catch (\Throwable $e) {
            $second->rollBack();

            throw $e;
        }

        $this->assertRefused(
            RefusalReason::RegistrationEnded,
            fn () => $this->complete(Registration::query()->findOrFail($registration->id)),
        );

        self::assertSame(1, Account::query()->count(), 'the loser created a second account');
        self::assertSame(0, DB::table('auth_sessions')->count(), 'the loser opened a session');
    }

    /**
     * CARRIES WEIGHT. REQUIRED CASE: two registrations, one canonical email.
     *
     * The other connection is inserting that address and has not committed. The
     * unique index — not a check in this application — is what makes this
     * completion wait, and then refuse. An application pre-check would have
     * read no account at all and inserted the second one.
     */
    public function test_a_completion_claiming_an_uncommitted_email_blocks_and_then_refuses(): void
    {
        $this->assertTheIndexArbitrates(
            claimant: fn (ConnectionInterface $c) => $this->insertAccount($c, self::EMAIL, self::OTHER_PHONE),
            expected: RefusalReason::EmailAlreadyRegistered,
        );

        self::assertSame(1, Account::query()->where('email', self::EMAIL)->count());
    }

    /** The same, on `accounts.phone_e164`. */
    public function test_a_completion_claiming_an_uncommitted_phone_blocks_and_then_refuses(): void
    {
        $this->assertTheIndexArbitrates(
            claimant: fn (ConnectionInterface $c) => $this->insertAccount($c, null, self::PHONE),
            expected: RefusalReason::PhoneAlreadyRegistered,
        );

        self::assertSame(1, Account::query()->where('phone_e164', self::PHONE)->count());
    }

    // --------------------------------------------------------------- helpers

    /**
     * A registration proven on both channels, racing against an uncommitted
     * account that claims one of its identifiers.
     *
     * @param  callable(ConnectionInterface): void  $claimant
     */
    private function assertTheIndexArbitrates(callable $claimant, RefusalReason $expected): void
    {
        $registration = $this->proven();

        $second = DB::connection(self::SECOND);
        $second->beginTransaction();

        try {
            $claimant($second);

            // Blocked on the index rather than on any row this application
            // locked — nothing here holds `accounts`.
            self::assertTrue(
                $this->blocksOn(fn () => $this->complete($registration)),
                'the insert did not wait for the uncommitted account',
            );

            self::assertNull(
                Registration::query()->findOrFail($registration->id)->completed_at,
                'the registration was completed while its account was refused',
            );

            $second->commit();
        } catch (\Throwable $e) {
            $second->rollBack();

            throw $e;
        }

        $this->assertRefused(
            $expected,
            fn () => $this->complete(Registration::query()->findOrFail($registration->id)),
        );

        self::assertSame(1, Account::query()->count(), 'a second account was created');
        self::assertNull(Registration::query()->findOrFail($registration->id)->completed_at);
        self::assertSame(0, DB::table('auth_sessions')->count());
    }

    /**
     * Whether the act had to wait on a lock, bounded so a regression is a
     * failure rather than a hung suite.
     *
     * @param  callable(): mixed  $act
     */
    private function blocksOn(callable $act): bool
    {
        DB::statement("set lock_timeout = '750ms'");

        try {
            $act();

            return false;
        } catch (QueryException) {
            return true;
        } finally {
            DB::statement('set lock_timeout = 0');
        }
    }

    /**
     * @param  callable(): mixed  $act
     */
    private function assertRefused(RefusalReason $expected, callable $act): void
    {
        try {
            $act();
        } catch (RegistrationCompletionRefused $refused) {
            self::assertSame($expected, $refused->reason);

            return;
        }

        self::fail('completion was not refused');
    }

    private function complete(Registration $registration): void
    {
        app(CompleteRegistration::class)($registration, DeviceDescription::unknown());
    }

    /** An account written directly, on whichever connection is racing. */
    private function insertAccount(
        ConnectionInterface $connection,
        ?string $email,
        string $phone,
    ): void {
        $now = CarbonImmutable::now();

        $connection->table('accounts')->insert([
            'id' => (string) Str::uuid7(),
            'phone_e164' => $phone,
            'phone_verified_at' => $now,
            'email' => $email,
            'email_verified_at' => $email === null ? null : $now,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function proven(): Registration
    {
        $registrations = app(RegistrationService::class);
        $registration = Registration::query()->findOrFail($registrations->start()->registrationId);

        $send = app(SendRegistrationPasscode::class);
        $verify = app(VerifyRegistrationPasscode::class);

        $send($registration, OtpChannel::Email, self::EMAIL);
        $code = $this->email->lastCode();
        self::assertNotNull($code);
        self::assertTrue($verify($registration, OtpChannel::Email, $code));

        $send($registration, OtpChannel::Sms, self::PHONE);
        $code = $this->sms->lastCode();
        self::assertNotNull($code);
        self::assertTrue($verify($registration, OtpChannel::Sms, $code));

        return Registration::query()->findOrFail($registration->id);
    }
}
