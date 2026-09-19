<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\OtpChallenge;
use App\Otp\Email\EmailDeliveryFailed;
use App\Otp\Email\EmailSender;
use App\Otp\Email\InMemoryEmailSender;
use App\Otp\Email\InvalidEmailAddress;
use App\Otp\OtpChannel;
use App\Otp\OtpService;
use App\Otp\SendEmailPasscode;
use App\Otp\VerifyEmailPasscode;
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
 * The internal Email OTP capability: issuing, verifying, and the four things
 * it must never do.
 *
 * Two properties carry this file.
 *
 * The first is that an email address means ONE identity. Casing and outer
 * whitespace fold together, and nothing else does — a dot and a `+tag` are
 * part of the address, because whether they reach one mailbox is a fact about
 * one provider's routing rather than a fact about email. Guessing either way
 * merges or splits members, and both are unrecoverable once real data hangs
 * off the wrong identity.
 *
 * The second is that a successful verification proves possession and nothing
 * else. No account, no session, no token, no artifact a caller could keep.
 * That is the whole truthful content of this capability today, and the
 * assertions below are what stop it quietly becoming registration.
 */
final class EmailOtpCapabilityTest extends TestCase
{
    use CleansCommittedRows;

    /**
     * Truncation, NOT RefreshDatabase — for the reason PasscodeDeliveryTest
     * gives. RefreshDatabase holds an open transaction for the whole test, and
     * SendEmailPasscode refuses to run inside one. A harness that simulates
     * the exact condition under test cannot test it.
     */
    use DatabaseTruncation;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'accounts',
        'auth_sessions',
        'auth_tokens',
        'otp_challenges',
    ];

    private const EMAIL = 'member@ridemate.invalid';

    private InMemoryEmailSender $email;

    protected function setUp(): void
    {
        parent::setUp();

        $this->email = new InMemoryEmailSender;
        $this->app->instance(EmailSender::class, $this->email);
    }

    protected function tearDown(): void
    {
        // Before parent::tearDown(), which destroys the application.
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    // ------------------------------------------------------------- issuing

    public function test_issuing_writes_an_email_challenge_and_delivers_its_passcode(): void
    {
        $this->send(self::EMAIL);

        $challenge = OtpChallenge::query()->sole();
        self::assertSame(OtpChannel::Email, $challenge->channel);
        self::assertSame(self::EMAIL, $challenge->destination);
        self::assertTrue($challenge->isUnresolved());

        self::assertSame(1, $this->email->count());
        self::assertSame(self::EMAIL, $this->email->sent()[0]['email']);
    }

    /**
     * CARRIES WEIGHT. The row is durable BEFORE the code leaves the building.
     *
     * Asserted from inside the sender, which is the only place that can see
     * the ordering. A passcode delivered against a row that might still roll
     * back is a credential nothing recorded — strictly worse than a recorded
     * one that never arrived, which merely expires.
     *
     * The transaction depth is checked in the same breath: a sender observing
     * a depth above the caller's would be running inside the issuing
     * transaction, holding row locks across somebody else's network service.
     */
    public function test_delivery_happens_after_the_challenge_is_committed_and_outside_its_transaction(): void
    {
        self::assertSame(0, DB::transactionLevel(), 'the test must start outside a transaction');

        $observed = null;
        $persisted = null;

        $this->app->instance(EmailSender::class, new class($observed, $persisted) implements EmailSender
        {
            public function __construct(public ?int &$level, public ?int &$rows) {}

            public function sendPasscode(string $emailAddress, string $code): void
            {
                $this->level = DB::transactionLevel();
                $this->rows = OtpChallenge::query()->count();
            }
        });

        $this->send(self::EMAIL);

        self::assertSame(0, $observed, 'the sender ran inside the issuing transaction');
        self::assertSame(1, $persisted, 'the sender ran before the challenge was persisted');
    }

    /**
     * A caller wrapping this in a transaction would turn the commit into a
     * savepoint release, so the passcode would be handed out for a row that
     * could still disappear. Refused before anything is issued or sent.
     */
    public function test_issuing_inside_an_outer_transaction_is_refused_before_delivery(): void
    {
        try {
            DB::transaction(function (): void {
                $this->send(self::EMAIL);
            });
            self::fail('issuance inside a transaction should have been refused');
        } catch (RuntimeException) {
            // expected
        }

        self::assertSame(0, OtpChallenge::query()->count());
        self::assertSame(0, $this->email->count(), 'a passcode was delivered from inside a transaction');
    }

    // ----------------------------------------------------- one identity

    /**
     * CARRIES WEIGHT. Casing and outer whitespace are not identity.
     *
     * Asserted through the cooldown rather than by comparing strings: the
     * second spelling is refused because the service recognised it as the same
     * destination, which is the property that matters. A string comparison
     * would pass even if the lock, the index and the budget disagreed.
     */
    public function test_casing_and_surrounding_whitespace_fold_into_one_destination(): void
    {
        $this->send('  Member@RideMate.Invalid  ');

        try {
            $this->send('MEMBER@RIDEMATE.INVALID');
            self::fail('the second spelling was treated as a different destination');
        } catch (TooManyRequestsHttpException) {
            // expected: same identity, so the resend cooldown applies.
        }

        $challenge = OtpChallenge::query()->sole();
        self::assertSame(self::EMAIL, $challenge->destination);
        self::assertSame(1, $this->email->count());
    }

    /**
     * Case folding is not ASCII-only, or the rule above would be true for part
     * of the alphabet and silently false for the rest.
     */
    public function test_case_folding_covers_non_ascii_addresses(): void
    {
        $this->send('Äli@Example.Invalid');

        self::assertSame('äli@example.invalid', OtpChallenge::query()->sole()->destination);
    }

    /**
     * CARRIES WEIGHT, IN THE OTHER DIRECTION. A dot is part of the address.
     *
     * Removing dots is one provider's routing rule, not email's. Applying it
     * here would fold two people into one identity, and there is no way back
     * once both have data.
     */
    public function test_dots_are_significant(): void
    {
        $this->send('a.li@ridemate.invalid');
        $this->send('ali@ridemate.invalid');

        self::assertSame(
            ['a.li@ridemate.invalid', 'ali@ridemate.invalid'],
            $this->destinations(),
        );
    }

    /** And so is a `+tag`: subaddressing is not stripped or reinterpreted. */
    public function test_plus_tags_are_significant(): void
    {
        $this->send('ali+rides@ridemate.invalid');
        $this->send('ali@ridemate.invalid');

        self::assertSame(
            ['ali+rides@ridemate.invalid', 'ali@ridemate.invalid'],
            $this->destinations(),
        );
    }

    // --------------------------------------------------- refusing input

    /**
     * CARRIES WEIGHT. Nothing unparseable reaches OtpService.
     *
     * Not merely "it is refused": no challenge is written and no budget is
     * spent, so a malformed address cannot consume a member's hourly cap on
     * their behalf. A quoted local part and an address-literal domain are in
     * this list because they are where case genuinely carries meaning — they
     * are refused rather than lowercased.
     */
    public function test_invalid_addresses_never_reach_the_challenge_table(): void
    {
        $refused = [
            '',
            '   ',
            'not-an-email',
            'a@b',
            'ali@localhost',
            "member@ridemate.invalid\n",
            "member@ridemate.invalid\r\nbcc: someone@elsewhere.invalid",
            '"a b"@ridemate.invalid',
            'ali@[127.0.0.1]',
            str_repeat('a', 250).'@ridemate.invalid',
        ];

        foreach ($refused as $input) {
            try {
                $this->send($input);
                self::fail('an invalid address was accepted');
            } catch (InvalidEmailAddress) {
                // expected
            }

            try {
                app(VerifyEmailPasscode::class)($input, '123456');
                self::fail('an invalid address was accepted for verification');
            } catch (InvalidEmailAddress) {
                // expected
            }
        }

        self::assertSame(0, OtpChallenge::query()->count());
        self::assertSame(0, $this->email->count());
    }

    // ------------------------------------------------------ verification

    public function test_a_valid_passcode_verifies_and_is_consumed_exactly_once(): void
    {
        $this->send(self::EMAIL);
        $code = $this->email->lastCode();
        self::assertNotNull($code);

        self::assertTrue($this->verify(self::EMAIL, $code));
        self::assertNotNull(OtpChallenge::query()->sole()->consumed_at);

        // Single use. A replayed code is the same refusal as a wrong one.
        self::assertFalse($this->verify(self::EMAIL, $code));
    }

    /** The same canonicalization on the way back in, or the row is unfindable. */
    public function test_verification_accepts_any_spelling_of_the_address_it_was_issued_for(): void
    {
        $this->send(self::EMAIL);
        $code = $this->email->lastCode();
        self::assertNotNull($code);

        self::assertTrue($this->verify('  MEMBER@RideMate.Invalid ', $code));
    }

    /**
     * The existing OTP invariants are the email channel's invariants too:
     * wrong, expired and exhausted are one answer, and each costs what it
     * always cost.
     */
    public function test_a_wrong_passcode_fails_and_spends_one_attempt(): void
    {
        $this->send(self::EMAIL);

        self::assertFalse($this->verify(self::EMAIL, '000000'));
        self::assertSame(1, OtpChallenge::query()->sole()->attempts);
        self::assertTrue(OtpChallenge::query()->sole()->isUnresolved());
    }

    public function test_an_expired_passcode_does_not_verify(): void
    {
        $this->send(self::EMAIL);
        $code = $this->email->lastCode();
        self::assertNotNull($code);

        $this->travel(config('ridemate.otp.ttl') + 1)->seconds();

        self::assertFalse($this->verify(self::EMAIL, $code));
        self::assertNull(OtpChallenge::query()->sole()->consumed_at);
    }

    public function test_the_attempt_ceiling_holds_and_the_right_code_no_longer_works(): void
    {
        $this->send(self::EMAIL);
        $code = $this->email->lastCode();
        self::assertNotNull($code);

        $max = (int) config('ridemate.otp.max_attempts');

        for ($i = 0; $i < $max; $i++) {
            self::assertFalse($this->verify(self::EMAIL, '000000'));
        }

        self::assertSame($max, OtpChallenge::query()->sole()->attempts);

        // Exhausted means exhausted: the correct code is refused too, and does
        // not cost a further attempt.
        self::assertFalse($this->verify(self::EMAIL, $code));
        self::assertSame($max, OtpChallenge::query()->sole()->attempts);
    }

    /**
     * CARRIES WEIGHT, AND IS THE POINT OF THE WHOLE SLICE.
     *
     * A verified email address proves possession at that instant and produces
     * nothing else. The day this grows a public endpoint it must not have
     * quietly become registration in the meantime, and these four counts are
     * what would notice.
     */
    public function test_verification_creates_no_account_and_issues_no_credentials(): void
    {
        $this->send(self::EMAIL);
        $code = $this->email->lastCode();
        self::assertNotNull($code);

        self::assertTrue($this->verify(self::EMAIL, $code));

        self::assertSame(0, Account::query()->count(), 'verification created an account');
        self::assertSame(0, DB::table('auth_sessions')->count(), 'verification opened a session');
        self::assertSame(0, DB::table('auth_tokens')->count(), 'verification issued a token');
    }

    // -------------------------------------------------- delivery failure

    /**
     * CARRIES WEIGHT, AND IS THE HOLE THIS HAD TO NOT HAVE.
     *
     * The tempting reaction to a failed send is to let the member try again at
     * once. That would make delivery failure — something an attacker can
     * provoke — a way to bypass the cooldown and pump passcodes at an address.
     * The row is committed, so the cooldown applies exactly as it would after
     * a successful send.
     */
    public function test_a_failed_delivery_keeps_the_challenge_and_the_cooldown(): void
    {
        $this->email->fail();

        try {
            $this->send(self::EMAIL);
            self::fail('delivery should have failed');
        } catch (EmailDeliveryFailed) {
            // expected
        }

        self::assertSame(1, OtpChallenge::query()->where('destination', self::EMAIL)->count());

        $this->expectException(TooManyRequestsHttpException::class);
        $this->send(self::EMAIL);
    }

    /**
     * CARRIES WEIGHT. The failure is recorded; the credential and the identity
     * are not.
     */
    public function test_a_failed_delivery_logs_neither_the_address_nor_the_passcode(): void
    {
        /** @var list<MessageLogged> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e;
        });

        // Issued first so the passcode is known, then failed on the resend, so
        // the assertion below has a real code to look for.
        $this->send(self::EMAIL);
        $code = $this->email->lastCode();
        self::assertNotNull($code);

        $this->travel(config('ridemate.otp.resend_cooldown') + 1)->seconds();
        $this->email->fail();

        try {
            $this->send(self::EMAIL);
        } catch (EmailDeliveryFailed) {
            // expected
        }

        $written = implode("\n", array_map(
            static fn (MessageLogged $e): string => $e->message.' '.json_encode($e->context),
            $logged,
        ));

        self::assertStringContainsString('otp.delivery_failed', $written, 'the failure went unrecorded');
        self::assertStringNotContainsString(self::EMAIL, $written, 'an address reached the log');
        self::assertStringNotContainsString($code, $written, 'a passcode reached the log');
    }

    // ------------------------------------------------------- isolation

    /**
     * CARRIES WEIGHT. One string, two channels, no contact between them.
     *
     * The destinations are deliberately identical, which cannot happen through
     * the public API — a phone normalizes to E.164 — and is exactly the case
     * where a channel-blind lookup, lock or budget would show itself.
     */
    public function test_email_and_sms_challenges_are_isolated_for_an_identical_destination(): void
    {
        $otp = app(OtpService::class);

        $sms = $otp->issue(OtpChannel::Sms, self::EMAIL);
        $this->send(self::EMAIL);

        self::assertSame(2, OtpChallenge::query()->count(), 'one channel invalidated the other');

        // The email code does not open the SMS challenge, and vice versa.
        $emailCode = $this->email->lastCode();
        self::assertNotNull($emailCode);

        self::assertFalse($otp->verify(OtpChannel::Sms, self::EMAIL, $emailCode));
        self::assertFalse($this->verify(self::EMAIL, $sms->code));

        // And each still works on its own channel afterwards.
        self::assertTrue($this->verify(self::EMAIL, $emailCode));
        self::assertTrue($otp->verify(OtpChannel::Sms, self::EMAIL, $sms->code));
    }

    // --------------------------------------------------- staying internal

    /**
     * CARRIES WEIGHT. The capability is not reachable from outside.
     *
     * Asserted against the router's action names rather than a list of paths,
     * so a route added later cannot slip past by being called something else.
     * EmailDeliverySeamTest makes the same assertion about URIs and route
     * names; this one is about what a route actually resolves to.
     */
    public function test_no_registered_route_resolves_the_email_otp_capability(): void
    {
        $internal = [
            SendEmailPasscode::class,
            VerifyEmailPasscode::class,
            InMemoryEmailSender::class,
            EmailSender::class,
        ];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($internal as $class) {
                self::assertStringNotContainsString(
                    $class,
                    $route->getActionName(),
                    'a route resolves the internal email capability',
                );
            }
        }
    }

    // ------------------------------------------------------------- policy

    /**
     * The renamed budget is the same budget.
     *
     * `max_per_phone_per_hour` became `max_per_destination_per_hour` when it
     * stopped being true. The number did not change, the enforcement did not
     * change, and the old key is gone rather than left behind as a default
     * nothing reads.
     */
    public function test_the_hourly_budget_kept_its_number_under_its_new_name(): void
    {
        self::assertNull(config('ridemate.otp.max_per_phone_per_hour'));

        $cap = (int) config('ridemate.otp.max_per_destination_per_hour');
        self::assertSame(5, $cap);

        $cooldown = (int) config('ridemate.otp.resend_cooldown');

        for ($i = 0; $i < $cap; $i++) {
            $this->send(self::EMAIL);
            $this->travel($cooldown + 1)->seconds();
        }

        self::assertSame($cap, OtpChallenge::query()->count());

        $this->expectException(TooManyRequestsHttpException::class);
        $this->send(self::EMAIL);
    }

    // ------------------------------------------------------------ helpers

    private function send(string $emailAddress): void
    {
        app(SendEmailPasscode::class)($emailAddress);
    }

    private function verify(string $emailAddress, string $code): bool
    {
        return app(VerifyEmailPasscode::class)($emailAddress, $code);
    }

    /** @return list<string> */
    private function destinations(): array
    {
        $destinations = [];

        foreach (OtpChallenge::query()->orderBy('created_at')->get() as $challenge) {
            $destinations[] = $challenge->destination;
        }

        return $destinations;
    }
}
