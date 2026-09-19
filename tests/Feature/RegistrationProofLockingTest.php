<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OtpChallenge;
use App\Models\Registration;
use App\Otp\Email\EmailSender;
use App\Otp\Email\InMemoryEmailSender;
use App\Otp\OtpChannel;
use App\Otp\Sms\InMemorySmsSender;
use App\Otp\Sms\SmsSender;
use App\Registration\RegistrationService;
use App\Registration\SendRegistrationPasscode;
use App\Registration\VerifyRegistrationPasscode;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CleansCommittedRows;
use Tests\TestCase;

/**
 * The registration row really is locked, and it is locked FIRST.
 *
 * `RegistrationProofTest` proves the behaviour — write-once proofs, rollback on
 * a failed write, a code that cannot move between registrations. What it cannot
 * show is that two simultaneous verifications serialize, because both of its
 * calls run one after the other on a single connection where no lock is ever
 * contended.
 *
 * Two facts are asserted here, and the second is the one worth the file.
 *
 * The first: a verification blocks while another transaction holds the
 * registration row. Without that, two concurrent attempts both read
 * `email_verified_at IS NULL`, both consume a challenge — or the same one —
 * and both write a proof, so the write-once rule holds only when nobody is in
 * a hurry.
 *
 * The second: while it is blocked, the CHALLENGE has not been touched. That is
 * the lock ORDER. If the challenge were taken first, this pair of locks could
 * be acquired in two different orders by two different paths, which is a
 * deadlock that shows up under load and nowhere else.
 *
 * Same shape as OtpIssuanceLockingTest, and for the same reasons: no
 * RefreshDatabase — a single wrapping transaction cannot contend with itself —
 * and a lock_timeout, because the failure mode of a lock test is a hang.
 */
final class RegistrationProofLockingTest extends TestCase
{
    use CleansCommittedRows;

    private const SECOND = 'rm_registration_concurrent';

    private const EMAIL = 'member@ridemate.invalid';

    private InMemoryEmailSender $email;

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        config(['database.connections.'.self::SECOND => config('database.connections.pgsql')]);

        $this->truncateCommittedAuthRows();

        $this->email = new InMemoryEmailSender;
        $this->app->instance(EmailSender::class, $this->email);

        // Bound even though nothing here sends by SMS: resolving
        // SendRegistrationPasscode constructs both siblings, and the local-echo
        // SMS sender refuses to be constructed outside the local environment.
        $this->app->instance(SmsSender::class, new InMemorySmsSender);
    }

    protected function tearDown(): void
    {
        DB::disconnect(self::SECOND);
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    /**
     * CARRIES WEIGHT.
     *
     * The second connection holds the registration row. The verification on
     * this one must wait for it — and must not have reached the challenge.
     */
    public function test_verification_waits_on_the_registration_row_before_touching_the_challenge(): void
    {
        [$registration, $code] = $this->aRegistrationWithALiveChallenge();

        $second = DB::connection(self::SECOND);
        $second->beginTransaction();

        try {
            // Held for the length of this block, exactly as a concurrent
            // verification would hold it.
            $second->selectOne(
                'select id from registrations where id = ? for update',
                [$registration->id],
            );

            // Bounded, so a regression is a failure rather than a hung suite.
            DB::statement("set lock_timeout = '750ms'");

            $blocked = false;

            try {
                app(VerifyRegistrationPasscode::class)($registration, OtpChannel::Email, $code);
            } catch (QueryException) {
                $blocked = true;
            } finally {
                DB::statement('set lock_timeout = 0');
            }

            self::assertTrue($blocked, 'verification did not wait for the registration row');

            // THE ORDERING ASSERTION. It never got as far as the challenge: no
            // consumption, and no attempt spent on a code it never compared.
            $challenge = OtpChallenge::query()->firstOrFail();
            self::assertNull($challenge->consumed_at, 'the challenge was consumed before the registration was held');
            self::assertSame(0, $challenge->attempts, 'the challenge was read before the registration was held');
        } finally {
            $second->rollBack();
        }

        // And once the holder lets go, the same code still works — the blocked
        // attempt consumed nothing on its way out.
        self::assertTrue(
            app(VerifyRegistrationPasscode::class)($registration, OtpChannel::Email, $code),
        );
    }

    /**
     * CARRIES WEIGHT. REQUIRED CASE 1.
     *
     * Two verifications of one registration and channel produce ONE proof and
     * ONE consumption. The second arrives after the first has committed, which
     * is what the row lock turns a race into, and is refused by the write-once
     * rule rather than moving the timestamp.
     */
    public function test_a_second_verification_of_one_channel_cannot_write_a_second_proof(): void
    {
        [$registration, $code] = $this->aRegistrationWithALiveChallenge();

        $verify = app(VerifyRegistrationPasscode::class);

        self::assertTrue($verify($registration, OtpChannel::Email, $code));

        $proven = Registration::query()->findOrFail($registration->id)->email_verified_at;
        self::assertNotNull($proven);

        // The same code again, and the challenge it belonged to is consumed.
        self::assertFalse($verify(Registration::query()->findOrFail($registration->id), OtpChannel::Email, $code));

        self::assertEquals($proven, Registration::query()->findOrFail($registration->id)->email_verified_at);
        self::assertSame(1, OtpChallenge::query()->whereNotNull('consumed_at')->count());
    }

    /**
     * @return array{Registration, string}
     */
    private function aRegistrationWithALiveChallenge(): array
    {
        $registrations = app(RegistrationService::class);
        $registration = Registration::query()->findOrFail($registrations->start()->registrationId);

        app(SendRegistrationPasscode::class)($registration, OtpChannel::Email, self::EMAIL);

        $code = $this->email->lastCode();
        self::assertNotNull($code);

        return [$registration, $code];
    }
}
