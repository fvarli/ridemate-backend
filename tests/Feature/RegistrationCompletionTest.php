<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AuthenticateByPhone;
use App\Auth\DeviceDescription;
use App\Auth\TokenService;
use App\Http\Responses\AccountPayload;
use App\Models\Account;
use App\Models\AccountStatus;
use App\Models\AuthSession;
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
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * A registration that proved both possessions becomes exactly one account,
 * exactly once — and nothing else becomes anything.
 *
 * Four properties carry this file.
 *
 * The first is that the account is the registration's, not a new one built from
 * the same words. Both canonical identifiers and both proof timestamps move
 * across unchanged; a completion that restamped `phone_verified_at` with its
 * own clock would make the account assert something false about when possession
 * was demonstrated, and the registration it came from expires, so there would
 * be no second copy to correct it from.
 *
 * The second is exactly-once. `completed_at` and the account commit together,
 * and the credential that produced them resolves to nothing afterwards — so a
 * lost response is recovered by signing in, never by presenting the credential
 * again for a second session.
 *
 * The third is that a collision refuses rather than adopts. An account already
 * holding the number is somebody's, very likely a member who signed up before a
 * second channel existed, and the registration must not inherit it, merge with
 * it or overwrite anything on it.
 *
 * The fourth is that none of this is switched on. No route reaches it, no
 * response publishes anything new, and phone sign-in is what it was.
 *
 * Truncation, NOT RefreshDatabase — for the reason `RegistrationProofTest`
 * gives: proofs are earned here through the real send-and-verify path, and
 * passcode delivery refuses to run inside a transaction.
 */
final class RegistrationCompletionTest extends TestCase
{
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

    private const OTHER_PHONE = '+905329876543';

    private RegistrationService $registrations;

    private SendRegistrationPasscode $send;

    private VerifyRegistrationPasscode $verify;

    private CompleteRegistration $complete;

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
        $this->complete = app(CompleteRegistration::class);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    // ------------------------------------------------------------ completion

    /**
     * CARRIES WEIGHT. The whole slice in one assertion block.
     *
     * Two proofs, one account, carrying the identifiers that were proven and
     * the times they were proven at — not the time they were copied.
     */
    public function test_both_proofs_produce_exactly_one_account_carrying_what_was_proven(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-20T10:00:00Z'));

        $registration = $this->proven();
        $provenEmailAt = $registration->email_verified_at;
        $provenPhoneAt = $registration->phone_verified_at;
        self::assertNotNull($provenEmailAt);
        self::assertNotNull($provenPhoneAt);

        // Five minutes later, so "preserved" and "stamped now" cannot coincide.
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-09-20T10:05:00Z'));

        $completed = ($this->complete)($registration, new DeviceDescription(null, 'test', null));

        self::assertSame(1, Account::query()->count(), 'completion did not produce exactly one account');

        $account = Account::query()->findOrFail($completed->account->id);
        self::assertSame(self::EMAIL, $account->email);
        self::assertSame(self::PHONE, $account->phone_e164);

        // The identifiers are the canonical ones the registration bound, not a
        // second normalization of them.
        self::assertSame($registration->email, $account->email);
        self::assertSame($registration->phone_e164, $account->phone_e164);

        // THE TIMESTAMPS. Preserved to the microsecond, and demonstrably not
        // the completion time.
        self::assertSame(
            $provenEmailAt->format('Y-m-d H:i:s.u'),
            $account->email_verified_at?->format('Y-m-d H:i:s.u'),
        );
        self::assertSame(
            $provenPhoneAt->format('Y-m-d H:i:s.u'),
            $account->phone_verified_at->format('Y-m-d H:i:s.u'),
        );
        self::assertNotSame(
            CarbonImmutable::now()->format('Y-m-d H:i:s'),
            $account->phone_verified_at->format('Y-m-d H:i:s'),
            'the proof timestamp was restamped with the completion time',
        );

        // The existing account convention, not a new status.
        self::assertSame(AccountStatus::Active, $account->status);
        self::assertTrue($account->isActive());

        self::assertNotNull(Registration::query()->findOrFail($registration->id)->completed_at);
    }

    /** One completion opens one ordinary session, and its access token works. */
    public function test_completion_opens_one_normal_account_session(): void
    {
        $registration = $this->proven();

        $completed = ($this->complete)($registration, new DeviceDescription(null, 'test', null));

        self::assertSame(1, DB::table('auth_sessions')->count());
        self::assertSame(1, DB::table('auth_tokens')->count());

        $session = AuthSession::query()->findOrFail($completed->tokens->sessionId);
        self::assertSame($completed->account->id, $session->account_id);

        $context = app(TokenService::class)->authenticate($completed->tokens->accessToken);
        self::assertSame($completed->account->id, $context->account->id);
    }

    /** The registration's instance stops being stale the moment it completes. */
    public function test_the_callers_registration_reflects_the_completion(): void
    {
        $registration = $this->proven();

        ($this->complete)($registration, DeviceDescription::unknown());

        self::assertNotNull($registration->completed_at);
        self::assertFalse($registration->isAdvanceable());
    }

    // -------------------------------------------------------- exactly once

    /**
     * CARRIES WEIGHT. A registration credential is not an account credential.
     *
     * Once completed it authorizes nothing — not another account, and in
     * particular not another session. A client that lost the first response
     * signs in; it does not replay this.
     */
    public function test_a_completed_registration_cannot_be_completed_again(): void
    {
        $minted = $this->registrations->start();
        $registration = $this->proveBothChannels(
            Registration::query()->findOrFail($minted->registrationId),
        );

        ($this->complete)($registration, DeviceDescription::unknown());

        // The credential itself no longer resolves, which is where a caller
        // would be stopped first.
        self::assertNull($this->registrations->resolve($minted->credential));

        // And the action refuses even when handed the row directly.
        $this->assertRefused(
            RefusalReason::RegistrationEnded,
            fn () => ($this->complete)(
                Registration::query()->findOrFail($registration->id),
                DeviceDescription::unknown(),
            ),
        );

        self::assertSame(1, Account::query()->count(), 'a replay created a second account');
        self::assertSame(1, DB::table('auth_sessions')->count(), 'a replay minted a second session');
        self::assertSame(1, DB::table('auth_tokens')->count());
    }

    public function test_an_expired_registration_cannot_complete(): void
    {
        $registration = $this->proven();

        DB::table('registrations')->where('id', $registration->id)->update([
            'expires_at' => CarbonImmutable::now()->subSecond(),
        ]);

        $this->assertRefused(
            RefusalReason::RegistrationEnded,
            fn () => ($this->complete)(
                Registration::query()->findOrFail($registration->id),
                DeviceDescription::unknown(),
            ),
        );

        $this->assertNothingWasCreated($registration->id);
    }

    // ------------------------------------------------------- both or neither

    public function test_an_unproven_email_prevents_completion(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
        ($this->send)($registration, OtpChannel::Sms, self::PHONE);
        self::assertTrue(($this->verify)($registration, OtpChannel::Sms, $this->lastSmsCode()));

        // Bound, so the identifier is there; simply never proven.
        self::assertSame(self::EMAIL, $registration->fresh()?->email);

        $this->assertRefused(
            RefusalReason::NotFullyProven,
            fn () => ($this->complete)($registration, DeviceDescription::unknown()),
        );

        $this->assertNothingWasCreated($registration->id);
    }

    public function test_an_unproven_phone_prevents_completion(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Email, self::EMAIL);
        self::assertTrue(($this->verify)($registration, OtpChannel::Email, $this->lastEmailCode()));

        $this->assertRefused(
            RefusalReason::NotFullyProven,
            fn () => ($this->complete)($registration, DeviceDescription::unknown()),
        );

        $this->assertNothingWasCreated($registration->id);
    }

    /**
     * A channel that was never bound is the same refusal, and it must be.
     *
     * The `registrations` CHECK constraints make a proof without its identifier
     * unrepresentable, so "unbound" can never be the only thing wrong with a
     * row — but completion must not depend on that to avoid building an account
     * out of a NULL.
     */
    public function test_a_registration_missing_a_bound_identifier_cannot_complete(): void
    {
        $registration = $this->started();
        ($this->send)($registration, OtpChannel::Sms, self::PHONE);
        self::assertTrue(($this->verify)($registration, OtpChannel::Sms, $this->lastSmsCode()));

        $fresh = Registration::query()->findOrFail($registration->id);
        self::assertNull($fresh->email, 'the email channel was bound after all');
        self::assertNotNull($fresh->phone_verified_at);

        $this->assertRefused(
            RefusalReason::NotFullyProven,
            fn () => ($this->complete)($fresh, DeviceDescription::unknown()),
        );

        $this->assertNothingWasCreated($registration->id);
    }

    // ------------------------------------------------------------ collisions

    /**
     * CARRIES WEIGHT. An existing account is never adopted, merged or rewritten.
     *
     * The legacy population is every account that exists: phone-only, created
     * before a second channel did. Treating one as this registration's outcome
     * would hand a stranger somebody's account for the price of one SMS.
     */
    public function test_a_registration_cannot_take_an_existing_accounts_phone_number(): void
    {
        $legacy = $this->createAccount(self::PHONE);
        $before = DB::table('accounts')->where('id', $legacy->id)->first();
        self::assertNotNull($before);

        $registration = $this->proven();

        $this->assertRefused(
            RefusalReason::PhoneAlreadyRegistered,
            fn () => ($this->complete)($registration, DeviceDescription::unknown()),
        );

        // The existing row is byte-for-byte what it was — no email, no new
        // timestamp, not even an `updated_at` touch.
        self::assertEquals($before, DB::table('accounts')->where('id', $legacy->id)->first());

        self::assertSame(1, Account::query()->count(), 'the collision created an account anyway');
        $this->assertNothingWasCreated($registration->id, expectedAccounts: 1);
    }

    /**
     * CARRIES WEIGHT. Two registrations claiming one address; one account.
     *
     * The second arrives after the first has committed, which is what the
     * unique index turns a race into. `RegistrationCompletionLockingTest` proves
     * the index — rather than a check in this application — is what arbitrates.
     */
    public function test_two_registrations_claiming_one_email_produce_one_account(): void
    {
        $first = $this->proven(self::EMAIL, self::PHONE);
        $completed = ($this->complete)($first, DeviceDescription::unknown());
        $before = DB::table('accounts')->where('id', $completed->account->id)->first();

        // Past the destination-wide cooldown, which belongs to the address
        // rather than to either registration.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        $second = $this->proven(self::EMAIL, self::OTHER_PHONE);

        $this->assertRefused(
            RefusalReason::EmailAlreadyRegistered,
            fn () => ($this->complete)($second, DeviceDescription::unknown()),
        );

        self::assertSame(1, Account::query()->where('email', self::EMAIL)->count());
        self::assertSame(1, Account::query()->count());
        self::assertEquals($before, DB::table('accounts')->where('id', $completed->account->id)->first());
        self::assertNothingWasCreated($second->id, expectedAccounts: 1, expectedSessions: 1);
    }

    /** The same, on the number. */
    public function test_two_registrations_claiming_one_phone_produce_one_account(): void
    {
        $first = $this->proven(self::EMAIL, self::PHONE);
        ($this->complete)($first, DeviceDescription::unknown());

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(2));

        $second = $this->proven(self::OTHER_EMAIL, self::PHONE);

        $this->assertRefused(
            RefusalReason::PhoneAlreadyRegistered,
            fn () => ($this->complete)($second, DeviceDescription::unknown()),
        );

        self::assertSame(1, Account::query()->where('phone_e164', self::PHONE)->count());
        self::assertSame(1, Account::query()->count());
        self::assertNull(Account::query()->where('email', self::OTHER_EMAIL)->first());
        $this->assertNothingWasCreated($second->id, expectedAccounts: 1, expectedSessions: 1);
    }

    // ---------------------------------------------------------- atomicity

    /**
     * CARRIES WEIGHT. Never an account whose registration is still open.
     *
     * The failure is injected exactly where it matters: on the write to
     * `registrations`, after the account row has already been inserted.
     */
    public function test_a_failure_before_the_completion_commits_rolls_the_account_back(): void
    {
        $registration = $this->proven();

        Registration::updating(function (): void {
            throw new RuntimeException('injected failure after the account was inserted');
        });

        try {
            ($this->complete)($registration, DeviceDescription::unknown());
            self::fail('the injected failure did not propagate');
        } catch (RuntimeException $e) {
            self::assertNotInstanceOf(RegistrationCompletionRefused::class, $e);
        } finally {
            Registration::flushEventListeners();
        }

        $this->assertNothingWasCreated($registration->id);
    }

    /**
     * CARRIES WEIGHT. Never a completed registration with nothing to show.
     *
     * Injected on the session insert, which happens after `completed_at` has
     * been written — so a transaction that did not cover both would leave a
     * registration marked done and no account anywhere.
     */
    public function test_a_failure_after_the_completion_cannot_leave_it_without_an_account(): void
    {
        $registration = $this->proven();

        AuthSession::creating(function (): void {
            throw new RuntimeException('injected failure after the registration was completed');
        });

        try {
            ($this->complete)($registration, DeviceDescription::unknown());
            self::fail('the injected failure did not propagate');
        } catch (RuntimeException $e) {
            self::assertNotInstanceOf(RegistrationCompletionRefused::class, $e);
        } finally {
            AuthSession::flushEventListeners();
        }

        $this->assertNothingWasCreated($registration->id);
    }

    // ------------------------------------------------- nothing else changed

    /**
     * CARRIES WEIGHT. Phone sign-in is what it was, and it recognizes the
     * account a registration produced rather than making a second one.
     */
    public function test_phone_sign_in_still_finds_the_account_a_registration_produced(): void
    {
        $registration = $this->proven();
        $completed = ($this->complete)($registration, DeviceDescription::unknown());

        $pair = app(AuthenticateByPhone::class)(
            self::PHONE,
            new DeviceDescription(null, 'test', null),
        );

        self::assertSame(1, Account::query()->count(), 'signing in created a second account');

        $session = AuthSession::query()->findOrFail($pair->sessionId);
        self::assertSame($completed->account->id, $session->account_id);

        $account = Account::query()->findOrFail($completed->account->id);
        self::assertSame(self::EMAIL, $account->email, 'signing in rewrote the proven address');
    }

    /** A legacy phone-only account is not touched by somebody else's completion. */
    public function test_a_legacy_phone_only_account_survives_a_completion_untouched(): void
    {
        $legacy = $this->createAccount(self::OTHER_PHONE);
        $before = DB::table('accounts')->where('id', $legacy->id)->first();

        ($this->complete)($this->proven(), DeviceDescription::unknown());

        self::assertEquals($before, DB::table('accounts')->where('id', $legacy->id)->first());
        self::assertSame(2, Account::query()->count());

        $reread = Account::query()->findOrFail($legacy->id);
        self::assertNull($reread->email);
        self::assertNull($reread->email_verified_at);
        self::assertTrue($reread->isActive());
    }

    /**
     * CARRIES WEIGHT. No response learned anything from this slice.
     *
     * `AccountPayload` names its fields one by one, and an account that now
     * genuinely holds an address is the case that would expose it if it did not.
     */
    public function test_the_account_response_still_publishes_no_email(): void
    {
        $completed = ($this->complete)($this->proven(), DeviceDescription::unknown());

        $payload = AccountPayload::from(Account::query()->findOrFail($completed->account->id));

        self::assertSame(
            ['id', 'phone_e164', 'phone_verified_at', 'status', 'created_at'],
            array_keys($payload['account']),
        );

        $encoded = (string) json_encode($payload);
        self::assertStringNotContainsString(self::EMAIL, $encoded);
        self::assertStringNotContainsString('email', $encoded);
    }

    /** Nothing public reaches any of this. */
    public function test_no_route_resolves_the_completion_capability(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            self::assertStringNotContainsString(CompleteRegistration::class, $route->getActionName());
            self::assertStringNotContainsString('registration', $route->uri());
            self::assertStringNotContainsString('register', $route->uri());
        }
    }

    /**
     * CARRIES WEIGHT. Neither identifier, nor the credential, nor a token may
     * reach the logging pipeline.
     */
    public function test_completion_logs_no_identifier_credential_or_token(): void
    {
        $minted = $this->registrations->start();
        $registration = $this->proveBothChannels(
            Registration::query()->findOrFail($minted->registrationId),
        );

        /** @var list<MessageLogged> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e;
        });

        $completed = ($this->complete)($registration, DeviceDescription::unknown());

        foreach ($logged as $entry) {
            $line = $entry->message.' '.json_encode($entry->context);

            foreach ([
                self::EMAIL,
                self::PHONE,
                $minted->credential,
                $completed->tokens->accessToken,
                $completed->tokens->refreshToken,
            ] as $secret) {
                self::assertStringNotContainsString($secret, $line);
            }
        }
    }

    /** A completed credential cannot be presented as an access token either. */
    public function test_a_registration_credential_is_not_an_access_token(): void
    {
        $minted = $this->registrations->start();
        $this->proveBothChannels(Registration::query()->findOrFail($minted->registrationId));

        ($this->complete)(
            Registration::query()->findOrFail($minted->registrationId),
            DeviceDescription::unknown(),
        );

        $this->expectException(AuthenticationException::class);

        app(TokenService::class)->authenticate($minted->credential);
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param  callable(): mixed  $act
     */
    private function assertRefused(RefusalReason $expected, callable $act): void
    {
        try {
            $act();
        } catch (RegistrationCompletionRefused $refused) {
            self::assertSame($expected, $refused->reason);

            // A refusal message is developer-facing and reaches the exception
            // renderer. It must never carry an identifier.
            foreach ([self::EMAIL, self::OTHER_EMAIL, self::PHONE, self::OTHER_PHONE] as $identifier) {
                self::assertStringNotContainsString($identifier, $refused->getMessage());
            }

            return;
        }

        self::fail('completion was not refused');
    }

    /** The registration is still open, and the refusal wrote nothing. */
    private function assertNothingWasCreated(
        string $registrationId,
        int $expectedAccounts = 0,
        int $expectedSessions = 0,
    ): void {
        self::assertNull(
            Registration::query()->findOrFail($registrationId)->completed_at,
            'the registration was completed anyway',
        );
        self::assertSame($expectedAccounts, Account::query()->count());
        self::assertSame($expectedSessions, DB::table('auth_sessions')->count());
        self::assertSame($expectedSessions, DB::table('auth_tokens')->count());
    }

    private function started(): Registration
    {
        return Registration::query()->findOrFail($this->registrations->start()->registrationId);
    }

    private function proven(string $email = self::EMAIL, string $phone = self::PHONE): Registration
    {
        return $this->proveBothChannels($this->started(), $email, $phone);
    }

    private function proveBothChannels(
        Registration $registration,
        string $email = self::EMAIL,
        string $phone = self::PHONE,
    ): Registration {
        ($this->send)($registration, OtpChannel::Email, $email);
        self::assertTrue(($this->verify)($registration, OtpChannel::Email, $this->lastEmailCode()));

        ($this->send)($registration, OtpChannel::Sms, $phone);
        self::assertTrue(($this->verify)($registration, OtpChannel::Sms, $this->lastSmsCode()));

        return Registration::query()->findOrFail($registration->id);
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
}
