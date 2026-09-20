<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\OtpChallenge;
use App\Models\Registration;
use App\Otp\Email\EmailSender;
use App\Otp\Email\InMemoryEmailSender;
use App\Otp\OtpChannel;
use App\Otp\OtpScope;
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
use Tests\Support\CreatesAccounts;
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

    use CreatesAccounts;
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
        CarbonImmutable::setTestNow();
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
     * CARRIES WEIGHT, AND IS THE POINT OF THE CORRECTION.
     *
     * A code issued for registration A can never verify registration B, even
     * though both bind the exact same canonical address.
     *
     * This is now structural rather than a matter of reachability. A live
     * challenge used to be identified by `(channel, destination)` alone, so two
     * registrations naming one address shared one row and whoever held the code
     * could spend it on either. `otp_challenges.registration_id` makes the
     * identity `(registration_id, channel)` instead, so B's lookup cannot find
     * A's row at all — there is no ordering of sends, no invalidation rule and
     * no caller discipline involved.
     */
    public function test_a_code_issued_for_one_registration_cannot_prove_another(): void
    {
        [$a, $b, $codeForA, $codeForB] = $this->twoRegistrationsOnOneEmail();

        self::assertNotSame($codeForA, $codeForB);

        self::assertFalse(($this->verify)($b, OtpChannel::Email, $codeForA));
        self::assertNull($b->fresh()?->email_verified_at);

        self::assertFalse(($this->verify)($a, OtpChannel::Email, $codeForB));
        self::assertNull($a->fresh()?->email_verified_at);

        // Each still proves its own, which is what makes the refusals above
        // isolation rather than both challenges simply being broken.
        self::assertTrue(($this->verify)($a, OtpChannel::Email, $codeForA));
        self::assertTrue(($this->verify)($b, OtpChannel::Email, $codeForB));
    }

    /** The same invariant on the phone channel. */
    public function test_a_code_issued_for_one_registration_cannot_prove_another_by_phone(): void
    {
        $a = $this->started();
        $b = $this->started();

        ($this->send)($a, OtpChannel::Sms, self::PHONE);
        $codeForA = $this->lastSmsCode();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        try {
            ($this->send)($b, OtpChannel::Sms, self::PHONE);
            $codeForB = $this->lastSmsCode();

            self::assertFalse(($this->verify)($b, OtpChannel::Sms, $codeForA));
            self::assertFalse(($this->verify)($a, OtpChannel::Sms, $codeForB));

            self::assertTrue(($this->verify)($a, OtpChannel::Sms, $codeForA));
            self::assertTrue(($this->verify)($b, OtpChannel::Sms, $codeForB));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * CARRIES WEIGHT. A resend for one registration leaves the other's alone.
     *
     * Issuance invalidates its predecessors WITHIN ITS SCOPE. Before the
     * correction it invalidated every unresolved row for the destination, so
     * one member asking again silently killed a stranger's live code.
     */
    public function test_a_resend_for_one_registration_does_not_invalidate_the_other(): void
    {
        [$a, $b, $codeForA, $codeForB] = $this->twoRegistrationsOnOneEmail();

        // Clears the cooldown B's send started — it is the address's, not B's.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        ($this->send)($a, OtpChannel::Email, self::EMAIL);
        $resentForA = $this->lastEmailCode();

        // A's own predecessor died, as it always has.
        self::assertFalse(($this->verify)($a, OtpChannel::Email, $codeForA));

        // B's did not.
        self::assertTrue(($this->verify)($b, OtpChannel::Email, $codeForB));

        self::assertTrue(($this->verify)($a, OtpChannel::Email, $resentForA));
    }

    // --------------------------------------- registration vs. the sign-in path

    /**
     * CARRIES WEIGHT, AND IS THE WORSE HALF OF WHAT WAS WRONG.
     *
     * A registration SMS code used to be a sign-in code: same channel, same
     * number, same namespace. Whoever held one could present it at
     * `POST /auth/otp/verify` and be handed an account.
     */
    public function test_a_registration_code_cannot_sign_anybody_in(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Sms, self::PHONE);
        $registrationCode = $this->lastSmsCode();

        self::assertFalse(
            app(OtpService::class)->verify(
                OtpChannel::Sms,
                self::PHONE,
                $registrationCode,
                OtpScope::standalone(),
            ),
        );

        self::assertSame(0, Account::query()->count(), 'a registration code created an account');

        // And it is still spendable where it belongs, so the refusal above was
        // isolation rather than the code being consumed on its way through.
        self::assertTrue(($this->verify)($registration, OtpChannel::Sms, $registrationCode));
    }

    /** And the reverse: a sign-in code proves no registration. */
    public function test_a_sign_in_code_cannot_prove_a_registration(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Sms, self::PHONE);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        try {
            $signIn = app(OtpService::class)->issue(OtpChannel::Sms, self::PHONE, OtpScope::standalone());

            self::assertFalse(($this->verify)($registration, OtpChannel::Sms, $signIn->code));
            self::assertNull($registration->fresh()?->phone_verified_at);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * Issuing for a registration leaves a live sign-in challenge alone, and
     * vice versa. Neither namespace may supersede the other.
     */
    public function test_registration_and_sign_in_challenges_do_not_invalidate_each_other(): void
    {
        $registration = $this->started();

        $signIn = app(OtpService::class)->issue(OtpChannel::Sms, self::PHONE, OtpScope::standalone());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        try {
            ($this->send)($registration, OtpChannel::Sms, self::PHONE);
            $registrationCode = $this->lastSmsCode();

            // Both are still live, each in its own namespace.
            self::assertTrue(($this->verify)($registration, OtpChannel::Sms, $registrationCode));
            self::assertTrue(
                app(OtpService::class)->verify(
                    OtpChannel::Sms,
                    self::PHONE,
                    $signIn->code,
                    OtpScope::standalone(),
                ),
            );
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    // -------------------------------------------------- the budget is not scoped

    /**
     * CARRIES WEIGHT, IN THE OTHER DIRECTION.
     *
     * Scoping IDENTITY must not scope the ABUSE BUDGET. If each registration
     * carried its own cooldown and hourly cap, an attacker would mint a hundred
     * registrations and send one address a hundred times the passcodes — which
     * is the attack the budget exists for. The cooldown is destination-wide, so
     * a second registration cannot send to an address that was just sent to.
     */
    public function test_minting_registrations_does_not_multiply_the_destination_budget(): void
    {
        $a = $this->started();
        $b = $this->started();

        ($this->send)($a, OtpChannel::Email, self::EMAIL);

        $this->expectException(TooManyRequestsHttpException::class);

        ($this->send)($b, OtpChannel::Email, self::EMAIL);
    }

    /** The hourly cap counts every scope, so registrations cannot spend around it. */
    public function test_the_hourly_cap_counts_challenges_from_every_scope(): void
    {
        $cap = (int) config('ridemate.otp.max_per_destination_per_hour');
        $cooldown = (int) config('ridemate.otp.resend_cooldown');

        // One short of the cap, spent by the sign-in namespace.
        for ($i = 0; $i < $cap - 1; $i++) {
            app(OtpService::class)->issue(OtpChannel::Email, self::EMAIL, OtpScope::standalone());
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($cooldown + 1));
        }

        try {
            // A brand-new registration gets the last one, and then the address
            // is spent for everybody.
            ($this->send)($this->started(), OtpChannel::Email, self::EMAIL);
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($cooldown + 1));

            $this->expectException(TooManyRequestsHttpException::class);

            ($this->send)($this->started(), OtpChannel::Email, self::EMAIL);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    /**
     * The attempt ceiling belongs to the challenge, and each scope has its own.
     *
     * Asserted rather than assumed: the existing policy counts attempts on the
     * challenge ROW, not on the destination, so switching registrations does
     * give a fresh ceiling — bounded by the destination-wide issuance budget
     * above, which is what stops that being a way to guess indefinitely.
     */
    public function test_the_attempt_ceiling_is_per_challenge_and_issuance_is_what_bounds_guessing(): void
    {
        $max = (int) config('ridemate.otp.max_attempts');
        [$a, $b, $codeForA, $codeForB] = $this->twoRegistrationsOnOneEmail();

        for ($i = 0; $i < $max; $i++) {
            self::assertFalse(($this->verify)($a, OtpChannel::Email, '000000'));
        }

        // A's challenge is spent, even for the right code.
        self::assertFalse(($this->verify)($a, OtpChannel::Email, $codeForA));

        // B's ceiling is its own, because it is a different row — and reaching
        // this state cost a second issuance against the destination budget.
        self::assertTrue(($this->verify)($b, OtpChannel::Email, $codeForB));
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
            $this->issueDirectly($registration, OtpChannel::Email, self::EMAIL);

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

        app(OtpService::class)->verifyWithin(OtpChannel::Email, self::EMAIL, '000000', OtpScope::standalone());
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

        // Both columns: the table's CHECK refuses a completion that names no
        // account, which is the provenance invariant rather than anything this
        // file is about.
        $this->endRegistration($registration->id, [
            'account_id' => $this->createAccount('+905329876543')->id,
            'completed_at' => CarbonImmutable::now(),
        ]);

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

    /**
     * The public surface reaches these two through controllers, and through
     * nothing else.
     *
     * Phase 18 S4e gave them endpoints, so the older assertion — that no route
     * uri mentions a registration — stopped being the invariant. What still
     * holds, and matters more now that a route exists, is that neither action
     * is a route's own target: an invokable route resolving
     * `SendRegistrationPasscode` directly would be one with no request
     * validation, no credential resolution and no error mapping in front of it.
     */
    public function test_no_route_resolves_the_registration_proof_capability_directly(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ([SendRegistrationPasscode::class, VerifyRegistrationPasscode::class] as $class) {
                self::assertStringNotContainsString($class, $route->getActionName());
            }
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

    /**
     * Two registrations naming one address, each holding its own live challenge.
     *
     * The second send is past the cooldown, because the budget is
     * destination-wide and deliberately stays that way.
     *
     * @return array{Registration, Registration, string, string}
     */
    private function twoRegistrationsOnOneEmail(): array
    {
        $a = $this->started();
        $b = $this->started();

        ($this->send)($a, OtpChannel::Email, self::EMAIL);
        $codeForA = $this->lastEmailCode();

        // Past the cooldown, and LEFT there: a caller that resends afterwards
        // has to clear B's cooldown too, because it belongs to the address.
        // tearDown puts the clock back.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        ($this->send)($b, OtpChannel::Email, self::EMAIL);
        $codeForB = $this->lastEmailCode();

        return [$a, $b, $codeForA, $codeForB];
    }

    private function started(): Registration
    {
        return Registration::query()->findOrFail($this->registrations->start()->registrationId);
    }

    /** A fresh challenge in the registration's own scope, without sending one. */
    private function issueDirectly(Registration $registration, OtpChannel $channel, string $destination): void
    {
        app(OtpService::class)->issue($channel, $destination, OtpScope::forRegistration($registration->id));
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
