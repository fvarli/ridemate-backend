<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\OtpChallenge;
use App\Models\Registration;
use App\Otp\Email\EmailSender;
use App\Otp\Email\InMemoryEmailSender;
use App\Otp\OtpChannel;
use App\Otp\OtpService;
use App\Otp\Sms\InMemorySmsSender;
use App\Otp\Sms\SmsSender;
use App\Registration\RegistrationService;
use App\Registration\SendRegistrationPasscode;
use App\Registration\VerifyRegistrationPasscode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\Support\CleansCommittedRows;
use Tests\TestCase;

/**
 * Registration-scoped OTP proof: what a verified code proves, to which
 * registration, and the things it must never prove.
 *
 * Three properties carry this file.
 *
 * The first is that a caller cannot choose the destination. Verification takes
 * a registration, a channel and a code, and reads the destination from the row
 * — so a code earned on an address you control cannot be attached to a
 * registration naming somebody else's. That is not a check; there is nowhere to
 * put the wrong value.
 *
 * The second is that consumption and proof are one transaction. A challenge
 * spent without the proof it earned is a code that can never be verified again
 * on a registration that can therefore never complete, and the reverse is a
 * code that can be spent twice.
 *
 * The third is that proof is write-once. Neither a second verification nor a
 * resend may move, refresh or erase a timestamp that was already earned.
 */
final class RegistrationProofTest extends TestCase
{
    /**
     * Truncation, NOT RefreshDatabase — for the reason EmailOtpCapabilityTest
     * gives. RefreshDatabase holds an open transaction for the whole test, and
     * passcode delivery refuses to run inside one, so a harness that simulates
     * the exact condition under test cannot test it.
     */
    use CleansCommittedRows;

    use DatabaseTruncation;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'accounts',
        'auth_sessions',
        'auth_tokens',
        'otp_challenges',
        'registrations',
    ];

    private const EMAIL = 'member@ridemate.invalid';

    private const OTHER_EMAIL = 'someone-else@ridemate.invalid';

    private const PHONE = '+905321234567';

    private RegistrationService $registrations;

    private SendRegistrationPasscode $send;

    private VerifyRegistrationPasscode $verify;

    private InMemoryEmailSender $email;

    private InMemorySmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->email = new InMemoryEmailSender;
        $this->sms = new InMemorySmsSender;
        $this->app->instance(EmailSender::class, $this->email);
        $this->app->instance(SmsSender::class, $this->sms);

        $this->registrations = app(RegistrationService::class);
        $this->send = app(SendRegistrationPasscode::class);
        $this->verify = app(VerifyRegistrationPasscode::class);
    }

    protected function tearDown(): void
    {
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    // ------------------------------------------------------------- the basics

    public function test_sending_binds_the_destination_and_issues_a_challenge_on_that_channel(): void
    {
        $registration = $this->started();

        ($this->send)($registration, OtpChannel::Email, '  Member@RideMate.Invalid  ');

        self::assertSame(self::EMAIL, $registration->fresh()?->email);

        $challenge = OtpChallenge::query()->firstOrFail();
        self::assertSame(OtpChannel::Email, $challenge->channel);
        self::assertSame(self::EMAIL, $challenge->destination);
        self::assertTrue($challenge->isUnresolved());
    }

    public function test_a_valid_code_proves_its_channel_and_consumes_the_challenge(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);

        self::assertTrue(($this->verify)($registration, OtpChannel::Email, $this->lastEmailCode()));

        $fresh = $registration->fresh();
        self::assertNotNull($fresh?->email_verified_at);
        self::assertNull($fresh->phone_verified_at);
        self::assertNotNull(OtpChallenge::query()->firstOrFail()->consumed_at);
    }

    /**
     * REQUIRED CASE 2. Both channels land on one registration without
     * overwriting each other.
     */
    public function test_email_and_sms_proofs_both_land_on_one_registration(): void
    {
        $registration = $this->started();

        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
        self::assertTrue(($this->verify)($registration, OtpChannel::Email, $this->lastEmailCode()));

        ($this->send)($registration, OtpChannel::Sms, self::PHONE);
        self::assertTrue(($this->verify)($registration, OtpChannel::Sms, $this->lastSmsCode()));

        $fresh = $registration->fresh();
        self::assertNotNull($fresh?->email_verified_at);
        self::assertNotNull($fresh->phone_verified_at);
        self::assertTrue($fresh->isFullyProven());
    }

    // ------------------------------------------------- proof cannot be stolen

    /**
     * CARRIES WEIGHT. REQUIRED CASE 3.
     *
     * A code delivered for registration A is not proof for registration B.
     *
     * The mechanism is the one `otp_challenges` already had rather than a new
     * one: a destination naming a second registration can only come from that
     * registration asking for its own code, and issuing invalidates every
     * unresolved predecessor. So the moment B exists as something that could
     * verify, A's code is dead — and B's code is B's.
     *
     * WHAT THIS DOES NOT CLAIM, AND WHY THE TEST IS WRITTEN THIS WAY
     *
     * A challenge is keyed by `(channel, destination)` and carries no
     * registration column, deliberately: the OTP layer never learns what a code
     * is being used for. Two registrations naming ONE destination therefore
     * share that destination's single live challenge, and the last send wins
     * it. That is the accepted consequence of allowing several in-flight
     * registrations per address, and it is not a way in: every code goes to the
     * destination itself, so redeeming one still means reading that mailbox or
     * that phone.
     */
    public function test_a_code_issued_for_one_registration_cannot_prove_another(): void
    {
        $a = $this->started();
        $b = $this->started();

        ($this->send)($a, OtpChannel::Email, self::EMAIL);
        $codeForA = $this->lastEmailCode();

        // Past the cooldown, so B genuinely gets its own challenge — which is
        // the only way B comes to name this address at all.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        try {
            ($this->send)($b, OtpChannel::Email, self::EMAIL);

            self::assertNotSame($codeForA, $this->lastEmailCode());

            self::assertFalse(($this->verify)($b, OtpChannel::Email, $codeForA));
            self::assertNull($b->fresh()?->email_verified_at);

            // And A's own code is now equally dead, because B's issuance
            // superseded it. Neither registration can spend the other's.
            self::assertFalse(($this->verify)($a, OtpChannel::Email, $codeForA));
            self::assertNull($a->fresh()?->email_verified_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * CARRIES WEIGHT. REQUIRED CASE 4.
     *
     * A code earned for one destination cannot be moved to another, because the
     * destination cannot be moved: binding is write-once per channel, so there
     * is no state in which the registration names B while a challenge for A is
     * outstanding. Verification never takes a destination from the caller
     * either — the parameter does not exist.
     */
    public function test_a_code_for_one_destination_cannot_follow_a_change_of_destination(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
        $codeForFirst = $this->lastEmailCode();

        try {
            $this->registrations->bind($registration, OtpChannel::Email, self::OTHER_EMAIL);
            self::fail('the destination was changed after a challenge had been sent to it');
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(self::EMAIL, $registration->fresh()?->email);

        // And the original code still proves only what it was sent for.
        self::assertTrue(($this->verify)($registration, OtpChannel::Email, $codeForFirst));
    }

    /** A code for one channel is not a code for the other, on one registration. */
    public function test_a_code_does_not_prove_the_other_channel(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
        ($this->send)($registration, OtpChannel::Sms, self::PHONE);

        self::assertFalse(($this->verify)($registration, OtpChannel::Sms, $this->lastEmailCode()));
        self::assertNull($registration->fresh()?->phone_verified_at);
    }

    /** A channel nothing was ever sent on cannot be proven, or probed. */
    public function test_an_unbound_channel_cannot_be_verified(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);

        self::assertFalse(($this->verify)($registration, OtpChannel::Sms, $this->lastEmailCode()));
        self::assertNull($registration->fresh()?->phone_verified_at);
    }

    // ------------------------------------------------------------ write-once

    /**
     * CARRIES WEIGHT. REQUIRED CASE 7.
     *
     * Moving the timestamp would let anyone holding the credential refresh the
     * age of a proof they did not re-earn.
     */
    public function test_a_proof_timestamp_is_write_once(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
        self::assertTrue(($this->verify)($registration, OtpChannel::Email, $this->lastEmailCode()));

        $first = $registration->fresh()?->email_verified_at;
        self::assertNotNull($first);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        try {
            // A fresh challenge and a correct code for it, which is the
            // strongest form of the attempt.
            $this->issueDirectly(OtpChannel::Email, self::EMAIL);

            self::assertFalse(($this->verify)($registration, OtpChannel::Email, $this->lastEmailCode()));
            self::assertEquals($first, $registration->fresh()?->email_verified_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * CARRIES WEIGHT. REQUIRED CASE 8.
     *
     * Issuance invalidates every unresolved predecessor. A proof is not a
     * challenge, so nothing about a resend reaches it.
     */
    public function test_a_resend_after_a_proof_neither_erases_nor_refreshes_it(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
        self::assertTrue(($this->verify)($registration, OtpChannel::Email, $this->lastEmailCode()));

        $proven = $registration->fresh()?->email_verified_at;
        self::assertNotNull($proven);

        // Past the cooldown, so the resend is genuinely issued.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        try {
            ($this->send)($registration, OtpChannel::Email, self::EMAIL);

            self::assertEquals($proven, $registration->fresh()?->email_verified_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    // ------------------------------------------------------------- atomicity

    /**
     * CARRIES WEIGHT, AND IS THE POINT OF THE WHOLE SLICE. REQUIRED CASE 6.
     *
     * A failure after the code has been accepted but before the proof is
     * written must undo the consumption as well. Otherwise the member holds a
     * registration that can never be proven on that channel — the challenge is
     * spent, and a fresh one would prove nothing that a rolled-back write was
     * supposed to record.
     *
     * The failure is injected exactly where it matters: on the write to
     * `registrations`, after `verifyWithin()` has already consumed the row.
     */
    public function test_a_failure_before_the_proof_commits_rolls_back_the_consumption(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
        $code = $this->lastEmailCode();

        Registration::updating(function (): void {
            throw new RuntimeException('injected failure after the code was accepted');
        });

        try {
            ($this->verify)($registration, OtpChannel::Email, $code);
            self::fail('the injected failure did not propagate');
        } catch (RuntimeException) {
            // expected
        } finally {
            Registration::flushEventListeners();
        }

        self::assertNull(
            OtpChallenge::query()->firstOrFail()->consumed_at,
            'the challenge stayed consumed after the proof failed to commit',
        );
        self::assertNull($registration->fresh()?->email_verified_at);

        // And because nothing was consumed, the same code still works.
        self::assertTrue(($this->verify)($registration, OtpChannel::Email, $code));
    }

    /**
     * The inverse direction: `verifyWithin()` refuses to run without a
     * transaction, so no caller can consume a challenge outside one.
     */
    public function test_the_shared_primitive_refuses_to_run_without_a_transaction(): void
    {
        $this->expectException(RuntimeException::class);

        app(OtpService::class)->verifyWithin(OtpChannel::Email, self::EMAIL, '000000');
    }

    // -------------------------------------------------------- failure writes

    /** REQUIRED CASE 5. A wrong code proves nothing and spends one attempt. */
    public function test_a_wrong_code_writes_no_proof_and_spends_an_attempt(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);

        self::assertFalse(($this->verify)($registration, OtpChannel::Email, '000000'));

        self::assertNull($registration->fresh()?->email_verified_at);

        $challenge = OtpChallenge::query()->firstOrFail();
        self::assertSame(1, $challenge->attempts);
        self::assertNull($challenge->consumed_at);
    }

    public function test_an_expired_challenge_proves_nothing(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
        $code = $this->lastEmailCode();

        DB::table('otp_challenges')->update(['expires_at' => CarbonImmutable::now()->subSecond()]);

        self::assertFalse(($this->verify)($registration, OtpChannel::Email, $code));
        self::assertNull($registration->fresh()?->email_verified_at);
    }

    // ------------------------------------------------- registration lifecycle

    /**
     * REQUIRED CASE 9. An expired registration can neither be sent to nor
     * proven, and is never revived.
     */
    public function test_an_expired_registration_can_neither_send_nor_verify(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
        $code = $this->lastEmailCode();

        $this->endRegistration($registration->id, ['expires_at' => CarbonImmutable::now()->subSecond()]);

        self::assertFalse(($this->verify)(Registration::query()->findOrFail($registration->id), OtpChannel::Email, $code));

        $this->expectException(RuntimeException::class);

        ($this->send)(Registration::query()->findOrFail($registration->id), OtpChannel::Sms, self::PHONE);
    }

    /** A completed registration is finished. S4b never writes `completed_at` either. */
    public function test_a_completed_registration_can_neither_send_nor_verify(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
        $code = $this->lastEmailCode();

        $this->endRegistration($registration->id, ['completed_at' => CarbonImmutable::now()]);

        self::assertFalse(($this->verify)(Registration::query()->findOrFail($registration->id), OtpChannel::Email, $code));
        self::assertNull(Registration::query()->findOrFail($registration->id)->email_verified_at);

        $this->expectException(RuntimeException::class);

        ($this->send)(Registration::query()->findOrFail($registration->id), OtpChannel::Sms, self::PHONE);
    }

    /** An unknown credential resolves to nothing, so nothing can be advanced with it. */
    public function test_an_unknown_credential_resolves_to_no_registration(): void
    {
        self::assertNull($this->registrations->resolve('rmreg_not-a-credential.x'));
    }

    // ------------------------------------------------------ existing policy

    /** The per-destination cooldown is the registration path's cooldown too. */
    public function test_the_resend_cooldown_applies_to_a_registration_send(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);

        $this->expectException(TooManyRequestsHttpException::class);

        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
    }

    /** Two registrations on two destinations keep separate budgets, as before. */
    public function test_two_destinations_do_not_share_a_budget(): void
    {
        $a = $this->started();
        $b = $this->started();

        ($this->send)($a, OtpChannel::Email, self::EMAIL);
        ($this->send)($b, OtpChannel::Email, self::OTHER_EMAIL);

        self::assertSame(2, OtpChallenge::query()->count());
    }

    /** Delivery must not run inside a caller's transaction. */
    public function test_sending_inside_a_transaction_is_refused_before_anything_is_bound(): void
    {
        $registration = $this->started();

        DB::beginTransaction();

        try {
            ($this->send)($registration, OtpChannel::Email, self::EMAIL);
            self::fail('delivery ran inside a transaction');
        } catch (RuntimeException) {
            // expected
        } finally {
            DB::rollBack();
        }

        self::assertNull($registration->fresh()?->email);
        self::assertSame(0, OtpChallenge::query()->count());
    }

    // ------------------------------------------------------ what it is not

    /**
     * CARRIES WEIGHT. REQUIRED CASE 10.
     *
     * Two proofs on one registration are still not an account. What they
     * entitle anyone to is the completion slice's question, and it must not
     * have been answered here by accident.
     */
    public function test_proving_both_channels_creates_no_account_session_or_token(): void
    {
        $registration = $this->started();

        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
        ($this->verify)($registration, OtpChannel::Email, $this->lastEmailCode());
        ($this->send)($registration, OtpChannel::Sms, self::PHONE);
        ($this->verify)($registration, OtpChannel::Sms, $this->lastSmsCode());

        $fresh = Registration::query()->findOrFail($registration->id);
        self::assertTrue($fresh->isFullyProven());

        self::assertSame(0, Account::query()->count(), 'proof created an account');
        self::assertSame(0, DB::table('auth_sessions')->count(), 'proof opened a session');
        self::assertSame(0, DB::table('auth_tokens')->count(), 'proof issued a token');
        self::assertNull($fresh->completed_at, 'proof completed the registration');
    }

    /** Nothing public reaches any of this. */
    public function test_no_route_resolves_the_registration_proof_capability(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ([SendRegistrationPasscode::class, VerifyRegistrationPasscode::class] as $class) {
                self::assertStringNotContainsString($class, $route->getActionName());
            }

            self::assertStringNotContainsString('registration', $route->uri());
        }
    }

    /**
     * CARRIES WEIGHT. Neither the destination, the code nor the credential may
     * reach the logging pipeline.
     */
    public function test_the_registration_path_logs_no_destination_or_passcode(): void
    {
        /** @var list<MessageLogged> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e;
        });

        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
        $code = $this->lastEmailCode();
        ($this->verify)($registration, OtpChannel::Email, $code);

        foreach ($logged as $entry) {
            $line = $entry->message.' '.json_encode($entry->context);
            self::assertStringNotContainsString(self::EMAIL, $line);
            self::assertStringNotContainsString($code, $line);
        }
    }

    // --------------------------------------------------------------- helpers

    private function started(): Registration
    {
        return Registration::query()->findOrFail($this->registrations->start()->registrationId);
    }

    private function issueDirectly(OtpChannel $channel, string $destination): void
    {
        app(OtpService::class)->issue($channel, $destination);
    }

    private function lastEmailCode(): string
    {
        $code = $this->email->lastCode();
        self::assertNotNull($code);

        return $code;
    }

    private function lastSmsCode(): string
    {
        $code = $this->sms->lastCode();
        self::assertNotNull($code);

        return $code;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function endRegistration(string $id, array $values): void
    {
        DB::table('registrations')->where('id', $id)->update($values);
    }
}
