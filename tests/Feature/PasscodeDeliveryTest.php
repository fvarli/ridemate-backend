<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OtpChallenge;
use App\Otp\OtpService;
use App\Otp\SendPasscode;
use App\Otp\Sms\InMemorySmsSender;
use App\Otp\Sms\LocalEchoSmsSender;
use App\Otp\Sms\NullSmsSender;
use App\Otp\Sms\SmsDeliveryFailed;
use App\Otp\Sms\SmsSender;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

/**
 * Delivery: what happens around the transaction, and what must never reach a
 * log or a disk.
 */
final class PasscodeDeliveryTest extends TestCase
{
    /**
     * Truncation, NOT RefreshDatabase — and the difference is the point.
     *
     * RefreshDatabase wraps each test in a transaction, so DB::transactionLevel()
     * is never zero and SendPasscode's "must not run inside a transaction" guard
     * would refuse every call. A harness that simulates the exact condition
     * under test cannot test it: post-commit ordering, and the cooldown that
     * survives a failed delivery, both depend on a real commit happening.
     *
     * The tables are named explicitly rather than truncating everything found.
     * `spatial_ref_sys` belongs to PostGIS, and emptying it leaves the
     * extension registered and broken — the exact trap PostGisTest exists to
     * catch.
     */
    use DatabaseTruncation;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'accounts',
        'auth_sessions',
        'auth_tokens',
        'otp_challenges',
    ];

    private const PHONE = '+905321234567';

    private InMemorySmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new InMemorySmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
    }

    public function test_a_passcode_is_dispatched_to_the_number_that_asked(): void
    {
        app(SendPasscode::class)(self::PHONE);

        self::assertSame(1, $this->sms->count());
        self::assertSame(self::PHONE, $this->sms->sent()[0]['phone']);
        self::assertTrue(app(OtpService::class)->verify(self::PHONE, $this->sms->sent()[0]['code']));
    }

    /**
     * CARRIES WEIGHT. Delivery happens OUTSIDE the issuing transaction.
     *
     * Asserted by transaction depth rather than by literal commit, because
     * RefreshDatabase wraps the whole test in a transaction and a real commit
     * cannot happen inside it. The depth is the honest signal: if the sender
     * observed a deeper level than the caller, it would be running inside
     * OtpService's transaction — holding row locks and a connection open
     * across a call to someone else's network service.
     */
    public function test_delivery_does_not_run_inside_the_issuing_transaction(): void
    {
        self::assertSame(0, DB::transactionLevel(), 'the test must start outside a transaction');
        $observed = null;

        $this->app->instance(SmsSender::class, new class($observed) implements SmsSender
        {
            public function __construct(public ?int &$level) {}

            public function sendPasscode(string $phoneE164, string $code): void
            {
                $this->level = DB::transactionLevel();
            }
        });

        app(SendPasscode::class)(self::PHONE);

        self::assertSame(
            0,
            $observed,
            'the sender ran inside the issuing transaction',
        );
    }

    /**
     * A future caller must not be able to wrap this in a transaction, which
     * would turn the commit into a savepoint release and hand out a passcode
     * for a row that could still be rolled back.
     */
    public function test_dispatching_inside_a_transaction_is_refused(): void
    {
        $this->expectException(RuntimeException::class);

        DB::transaction(function (): void {
            app(SendPasscode::class)(self::PHONE);
        });
    }

    // ------------------------------------------------------ delivery failure

    public function test_a_failed_delivery_keeps_the_committed_challenge(): void
    {
        $this->sms->fail();

        try {
            app(SendPasscode::class)(self::PHONE);
            self::fail('delivery should have failed');
        } catch (SmsDeliveryFailed) {
            // expected
        }

        self::assertSame(1, OtpChallenge::query()->where('phone_e164', self::PHONE)->count());
    }

    /**
     * CARRIES WEIGHT, AND IS THE HOLE THIS HAD TO NOT HAVE.
     *
     * The tempting reaction to a failed send is to let the member try again
     * straight away. That would make delivery failure — something an attacker
     * can provoke — a way to bypass the resend cooldown entirely and pump
     * passcodes at a number. The row is committed, so the cooldown applies
     * exactly as it would after a successful send.
     */
    public function test_a_failed_delivery_does_not_reopen_the_cooldown(): void
    {
        $this->sms->fail();

        try {
            app(SendPasscode::class)(self::PHONE);
        } catch (SmsDeliveryFailed) {
            // expected
        }

        $this->expectException(TooManyRequestsHttpException::class);
        app(SendPasscode::class)(self::PHONE);
    }

    public function test_a_failed_delivery_is_recorded_without_the_passcode(): void
    {
        /** @var list<MessageLogged> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e;
        });

        $this->sms->fail();

        try {
            app(SendPasscode::class)(self::PHONE);
        } catch (SmsDeliveryFailed) {
            // expected
        }

        $messages = array_map(
            static fn (MessageLogged $e): string => $e->message.' '.json_encode($e->context),
            $logged,
        );

        self::assertNotEmpty($messages, 'a delivery failure should be recorded');
        self::assertStringContainsString('otp.delivery_failed', implode(' ', $messages));

        // The id is enough to find the row. The number is the member's
        // identity and must not be in a log line.
        self::assertStringNotContainsString(self::PHONE, implode(' ', $messages));
    }

    /**
     * CARRIES WEIGHT. No passcode reaches the logging pipeline. Ever.
     *
     * Covers the whole flow — issue, deliver, verify wrongly, verify correctly
     * — and inspects every MessageLogged event rather than one call site,
     * because the rule is about the pipeline and not about one line of code.
     */
    public function test_no_passcode_ever_reaches_the_log(): void
    {
        /** @var list<MessageLogged> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e;
        });

        app(SendPasscode::class)(self::PHONE);
        $code = $this->sms->lastCode();
        self::assertNotNull($code);

        $otp = app(OtpService::class);
        $otp->verify(self::PHONE, '000000');
        $otp->verify(self::PHONE, $code);

        foreach ($logged as $event) {
            $line = $event->message.' '.json_encode($event->context);
            self::assertStringNotContainsString($code, $line, 'a passcode reached the log');
        }
    }

    // ---------------------------------------------------------------- senders

    /**
     * The default refuses rather than discarding.
     *
     * A sender that swallowed would produce a deployment where sign-in looks
     * healthy, no member receives anything, and nothing is logged.
     */
    public function test_the_default_sender_refuses_loudly(): void
    {
        $this->expectException(SmsDeliveryFailed::class);
        (new NullSmsSender)->sendPasscode(self::PHONE, '123456');
    }

    public function test_the_configured_driver_defaults_to_the_refusing_sender(): void
    {
        self::assertSame('null', config('ridemate.sms.driver'));
    }

    /**
     * CARRIES WEIGHT. The local sender cannot exist outside local.
     *
     * The check is in the constructor rather than in send(), so a
     * misconfigured deployment fails when the container resolves it — not at
     * the first member's sign-in attempt, with the wrong sender already wired.
     */
    public function test_the_local_sender_refuses_to_construct_outside_local(): void
    {
        // The suite runs as `testing`, which is already not `local`.
        self::assertNotSame('local', $this->app->environment());

        $this->expectException(RuntimeException::class);
        new LocalEchoSmsSender;
    }

    public function test_the_local_sender_is_unreachable_through_the_container_when_not_local(): void
    {
        config(['ridemate.sms.driver' => 'local_echo']);
        $this->app->forgetInstance(SmsSender::class);

        $this->expectException(RuntimeException::class);
        app(SmsSender::class);
    }

    public function test_an_unknown_driver_is_a_configuration_error(): void
    {
        config(['ridemate.sms.driver' => 'twilio']);
        $this->app->forgetInstance(SmsSender::class);

        $this->expectException(\InvalidArgumentException::class);
        app(SmsSender::class);
    }
}
