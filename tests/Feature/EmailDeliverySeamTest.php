<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OtpChallenge;
use App\Otp\Email\EmailDeliveryFailed;
use App\Otp\Email\EmailSender;
use App\Otp\Email\InMemoryEmailSender;
use App\Otp\Email\NullEmailSender;
use App\Otp\OtpChannel;
use App\Otp\Sms\InMemorySmsSender;
use App\Otp\Sms\NullSmsSender;
use App\Otp\Sms\SmsSender;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use Tests\Support\CleansCommittedRows;
use Tests\TestCase;

/**
 * The email delivery seam: a contract, a refusing default, and a surface that
 * a configured provider does not widen.
 *
 * Two properties carry this file. The first is that the seam fails CLOSED —
 * the default refuses and an unrecognised driver is an error rather than a
 * silent downgrade to the refusing sender. The second is that having an
 * EmailSender in the container does not make Email OTP reachable: no route
 * issues one, and the existing passcode endpoint cannot be talked into one.
 * That second property is why this file survived the arrival of a real
 * adapter unchanged in substance — a provider makes delivery work, it does not
 * make a feature exist. The production adapter itself is
 * `ProductionEmailDeliveryTest`.
 *
 * The second property is asserted against CURRENT behaviour. The endpoint
 * ignores fields it does not declare, and this file does not change that to
 * make an assertion easier — the invariant is that email is unreachable, not
 * that a particular field returns a particular validation error.
 */
final class EmailDeliverySeamTest extends TestCase
{
    use CleansCommittedRows;

    /**
     * Truncation, not RefreshDatabase: one test posts to the passcode
     * endpoint, and SendPasscode refuses to run inside a transaction.
     */
    use DatabaseTruncation;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'accounts',
        'auth_sessions',
        'auth_tokens',
        'otp_challenges',
    ];

    private const PATH = '/api/v1/auth/otp';

    private const PHONE = '+905321234567';

    private const EMAIL = 'member@ridemate.invalid';

    private const CODE = '123456';

    protected function tearDown(): void
    {
        // Before parent::tearDown(), which destroys the application.
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    // ------------------------------------------------------------ the seam

    /**
     * The driver is named explicitly, for the reason the SMS driver test below
     * gives: a developer's `.env` may select the real adapter, and a test that
     * asserted the ambient value would prove nothing but what that machine
     * happens to be set to. That `null` is the SHIPPED default is a property of
     * config/ridemate.php; that `null` resolves to a sender which refuses is
     * this one, and `ProductionEmailDeliveryTest` holds the pair together.
     */
    public function test_the_contract_resolves_through_the_container(): void
    {
        config(['ridemate.email.driver' => 'null']);
        $this->app->forgetInstance(EmailSender::class);

        self::assertInstanceOf(NullEmailSender::class, app(EmailSender::class));
    }

    /**
     * CARRIES WEIGHT. No provider is configured, so delivery must refuse.
     *
     * A sender that accepted and discarded would produce the deployment this
     * seam exists to prevent: delivery appears to work and nobody receives
     * anything.
     */
    public function test_the_default_sender_refuses_loudly(): void
    {
        $this->expectException(EmailDeliveryFailed::class);

        (new NullEmailSender)->sendPasscode(self::EMAIL, self::CODE);
    }

    /**
     * CARRIES WEIGHT. An unrecognised driver is a configuration error.
     *
     * Not a fallback to the refusing sender: that would make a typo look like
     * a deliberate "no provider yet" and hide it until someone configured a
     * real provider and still received nothing.
     */
    public function test_an_unknown_driver_is_a_configuration_error(): void
    {
        config(['ridemate.email.driver' => 'resend']);
        $this->app->forgetInstance(EmailSender::class);

        $this->expectException(InvalidArgumentException::class);
        app(EmailSender::class);
    }

    public function test_the_test_double_captures_the_code_without_delivering_it(): void
    {
        $email = new InMemoryEmailSender;

        $email->sendPasscode(self::EMAIL, self::CODE);

        self::assertSame(1, $email->count());
        self::assertSame(
            [['email' => self::EMAIL, 'code' => self::CODE]],
            $email->sent(),
        );
        self::assertSame(self::CODE, $email->lastCode());
    }

    public function test_the_test_double_can_be_made_to_fail(): void
    {
        $email = new InMemoryEmailSender;
        $email->fail();

        $this->expectException(EmailDeliveryFailed::class);
        $email->sendPasscode(self::EMAIL, self::CODE);
    }

    // ----------------------------------------------------------- disclosure

    /**
     * CARRIES WEIGHT. A failure names neither the recipient nor the code.
     *
     * The message reaches the exception renderer, which puts it in the
     * response body in a debug build. An address there would also answer the
     * enumeration question the auth surface refuses to answer.
     */
    public function test_a_delivery_failure_discloses_neither_recipient_nor_code(): void
    {
        $failing = new InMemoryEmailSender;
        $failing->fail();

        foreach ([new NullEmailSender, $failing] as $sender) {
            try {
                $sender->sendPasscode(self::EMAIL, self::CODE);
                self::fail('the sender did not refuse');
            } catch (EmailDeliveryFailed $e) {
                self::assertStringNotContainsString(self::EMAIL, $e->getMessage());
                self::assertStringNotContainsString(self::CODE, $e->getMessage());
            }
        }
    }

    /**
     * CARRIES WEIGHT. Nothing in the seam reaches the logging pipeline.
     *
     * The SMS seam earned this test the hard way: a passcode in a log is a
     * credential in a log, and an address is the member's identity. The new
     * seam must not be the thing that reintroduces either.
     */
    public function test_the_seam_puts_nothing_sensitive_in_the_log(): void
    {
        /** @var list<MessageLogged> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e;
        });

        $email = new InMemoryEmailSender;
        $email->sendPasscode(self::EMAIL, self::CODE);

        // Named, so the refusal under test is the refusing sender's and not
        // whatever driver the machine's own .env happens to select.
        config(['ridemate.email.driver' => 'null']);
        $this->app->forgetInstance(EmailSender::class);

        try {
            app(EmailSender::class)->sendPasscode(self::EMAIL, self::CODE);
        } catch (EmailDeliveryFailed) {
            // The refusal is the point; what matters is what it did not write.
        }

        // Joined rather than looped: an empty pipeline is the expected result,
        // and a loop over nothing would assert nothing.
        $written = implode("\n", array_map(
            static fn (MessageLogged $e): string => $e->message.' '.json_encode($e->context),
            $logged,
        ));

        self::assertStringNotContainsString(self::CODE, $written, 'a passcode reached the log');
        self::assertStringNotContainsString(self::EMAIL, $written, 'an address reached the log');
    }

    // ------------------------------------------------------------ SMS stays

    /**
     * The SMS seam is untouched, and the two drivers are independent.
     *
     * The SMS driver is named explicitly because a developer's .env sets
     * `local_echo`, which refuses to construct outside the local environment —
     * the existing driver tests do the same.
     */
    public function test_sms_resolution_is_unchanged_and_independent(): void
    {
        config(['ridemate.sms.driver' => 'null']);
        $this->app->forgetInstance(SmsSender::class);

        self::assertInstanceOf(NullSmsSender::class, app(SmsSender::class));

        // An email driver nobody recognises is an email problem. It must not
        // reach across and change, or break, how SMS resolves.
        config(['ridemate.email.driver' => 'resend']);
        $this->app->forgetInstance(SmsSender::class);

        self::assertInstanceOf(NullSmsSender::class, app(SmsSender::class));
    }

    // ------------------------------------------------- email stays out of reach

    /**
     * CARRIES WEIGHT. No route delivers a passcode by email.
     *
     * Asserted against the router rather than a list of paths, so a route
     * added later cannot slip past by being named something else.
     */
    public function test_no_registered_route_exposes_email_authentication(): void
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            self::assertStringNotContainsStringIgnoringCase(
                'email',
                $route->uri(),
                'a route exposes an email surface',
            );
            self::assertStringNotContainsStringIgnoringCase(
                'email',
                (string) $route->getName(),
                'a route name exposes an email surface',
            );
        }
    }

    /**
     * CARRIES WEIGHT. The passcode endpoint cannot be talked into email.
     *
     * The request declares a phone and nothing else, and undeclared fields are
     * ignored — the behaviour this test PRESERVES rather than tightens. What
     * must hold is that they change nothing: the challenge is still an SMS
     * one, and the EmailSender bound in the container is never called.
     */
    public function test_extra_email_fields_cannot_reach_the_email_channel(): void
    {
        $sms = new InMemorySmsSender;
        $this->app->instance(SmsSender::class, $sms);

        $email = new InMemoryEmailSender;
        $this->app->instance(EmailSender::class, $email);

        $this->postJson(self::PATH, [
            'phone' => '0532 123 45 67',
            'email' => self::EMAIL,
            'channel' => 'email',
        ])->assertStatus(202);

        $challenge = OtpChallenge::query()->sole();
        self::assertSame(OtpChannel::Sms, $challenge->channel);
        self::assertSame(self::PHONE, $challenge->destination);

        self::assertSame(1, $sms->count());
        self::assertSame(0, $email->count(), 'the email sender was reached');
    }

    /**
     * And the endpoint still requires the phone it always required, so the
     * test above is passing for the right reason.
     */
    public function test_the_endpoint_still_requires_its_phone_contract(): void
    {
        $email = new InMemoryEmailSender;
        $this->app->instance(EmailSender::class, $email);

        $this->postJson(self::PATH, ['email' => self::EMAIL])
            ->assertStatus(422);

        self::assertSame(0, OtpChallenge::query()->count());
        self::assertSame(0, $email->count());
    }
}
