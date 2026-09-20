<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Otp\Email\EmailDeliveryFailed;
use App\Otp\Email\EmailSender;
use App\Otp\Email\LaravelMailEmailSender;
use App\Otp\Email\NullEmailSender;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Email as MimeEmail;
use Tests\TestCase;

/**
 * The production email adapter: a real message, a fail-closed configuration,
 * and a failure that says nothing.
 *
 * Three properties carry this file.
 *
 * The first is that the adapter produces the message it claims to. It is
 * asserted against the MIME Symfony would have put on the wire — subject,
 * recipient, sender, body — rather than against a mock of Laravel's mailer,
 * because the interesting mistakes (the code missing, the address on the wrong
 * header) all live in the message rather than in the call.
 *
 * The second is that NOTHING the transport says survives the adapter. A
 * Symfony transport exception carries the SMTP dialogue, and an SMTP rejection
 * routinely quotes the recipient back. That string must not reach the caller,
 * the renderer or the log.
 *
 * The third is that selecting this adapter cannot silently produce a
 * deployment that records passcodes instead of delivering them.
 *
 * NO NETWORK. The suite pins `MAIL_MAILER=array`, and the failure paths build
 * their own transport by hand. Nothing here opens a socket.
 */
final class ProductionEmailDeliveryTest extends TestCase
{
    private const EMAIL = 'member@ridemate.invalid';

    private const CODE = '123456';

    private function transport(): ArrayTransport
    {
        $transport = Mail::mailer('array')->getSymfonyTransport();

        self::assertInstanceOf(ArrayTransport::class, $transport);
        $transport->flush();

        return $transport;
    }

    /** The one message the array transport captured, as MIME. */
    private function onlySentMessage(ArrayTransport $transport): MimeEmail
    {
        self::assertCount(1, $transport->messages(), 'exactly one message should have been sent');

        $sent = $transport->messages()->first();
        self::assertInstanceOf(SentMessage::class, $sent);

        $message = $sent->getOriginalMessage();
        self::assertInstanceOf(MimeEmail::class, $message);

        return $message;
    }

    // --------------------------------------------------------- the message

    /**
     * CARRIES WEIGHT. The adapter really sends, through Laravel's mail seam.
     *
     * The seam is the whole point of the slice: `EmailSender` stays
     * provider-neutral and this is the only thing that knows a mailer exists.
     */
    public function test_it_sends_the_passcode_through_laravels_mail_seam(): void
    {
        config(['mail.from' => ['address' => 'hello@uselunexa.com', 'name' => 'RideMate']]);
        $transport = $this->transport();

        (new LaravelMailEmailSender(app(Mailer::class)))
            ->sendPasscode(self::EMAIL, self::CODE);

        $message = $this->onlySentMessage($transport);

        self::assertSame([self::EMAIL], array_map(
            static fn ($address): string => $address->getAddress(),
            $message->getTo(),
        ));
        self::assertSame('Your RideMate verification code', $message->getSubject());
        self::assertSame('hello@uselunexa.com', $message->getFrom()[0]->getAddress());
        self::assertSame('RideMate', $message->getFrom()[0]->getName());
    }

    /**
     * Minimal transactional content: identity, the code, its lifetime, and an
     * ignore line. Nothing that markets anything.
     */
    public function test_the_message_carries_the_code_its_lifetime_and_an_ignore_line(): void
    {
        config(['ridemate.otp.ttl' => 300]);
        $transport = $this->transport();

        (new LaravelMailEmailSender(app(Mailer::class)))
            ->sendPasscode(self::EMAIL, self::CODE);

        $body = (string) $this->onlySentMessage($transport)->getTextBody();

        self::assertStringContainsString('RideMate', $body);
        self::assertStringContainsString(self::CODE, $body);
        self::assertStringContainsString('5 minutes', $body);
        self::assertStringContainsStringIgnoringCase('did not ask for this code', $body);
    }

    /**
     * CARRIES WEIGHT. The lifetime is the configured policy, not a literal.
     *
     * A message promising five minutes while the policy said ten would be a
     * lie the moment somebody tuned the policy — and the member would stop
     * trusting the part of the message that matters.
     */
    public function test_the_stated_lifetime_follows_the_configured_ttl(): void
    {
        config(['ridemate.otp.ttl' => 600]);
        $transport = $this->transport();

        (new LaravelMailEmailSender(app(Mailer::class)))
            ->sendPasscode(self::EMAIL, self::CODE);

        $body = (string) $this->onlySentMessage($transport)->getTextBody();

        self::assertStringContainsString('10 minutes', $body);
        self::assertStringNotContainsString('5 minutes', $body);
    }

    /** The subject is not a place for a credential: previews and logs quote it. */
    public function test_the_subject_does_not_carry_the_passcode(): void
    {
        $transport = $this->transport();

        (new LaravelMailEmailSender(app(Mailer::class)))
            ->sendPasscode(self::EMAIL, self::CODE);

        self::assertStringNotContainsString(
            self::CODE,
            (string) $this->onlySentMessage($transport)->getSubject(),
        );
    }

    // ------------------------------------------------------- what a failure says

    /**
     * CARRIES WEIGHT. A transport failure becomes the seam's own exception,
     * and its message names neither recipient nor code.
     *
     * The original is not chained either: a `previous` reaches the exception
     * renderer, which puts it in the body in a debug build.
     */
    public function test_a_transport_failure_becomes_a_silent_delivery_failure(): void
    {
        $sender = new LaravelMailEmailSender($this->failingMailer(
            '550 5.1.1 <'.self::EMAIL.'>: Recipient address rejected: User unknown',
        ));

        try {
            $sender->sendPasscode(self::EMAIL, self::CODE);
            self::fail('the adapter did not refuse');
        } catch (EmailDeliveryFailed $e) {
            self::assertStringNotContainsString(self::EMAIL, $e->getMessage());
            self::assertStringNotContainsString(self::CODE, $e->getMessage());
            self::assertStringNotContainsString('550', $e->getMessage());
            self::assertNull($e->getPrevious(), 'the transport exception was chained through');
        }
    }

    /**
     * CARRIES WEIGHT. The log records that a transport failed, and nothing
     * about who it failed on.
     *
     * An SMTP rejection quotes the recipient back. Writing the provider's
     * response would put the member's address — the one string this surface
     * refuses to disclose — into a system that ships and retains everything.
     */
    public function test_delivery_failure_logging_leaks_neither_destination_nor_code(): void
    {
        /** @var list<MessageLogged> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e;
        });

        $response = '550 5.1.1 <'.self::EMAIL.'>: Recipient address rejected, code '.self::CODE;

        try {
            (new LaravelMailEmailSender($this->failingMailer($response)))
                ->sendPasscode(self::EMAIL, self::CODE);
        } catch (EmailDeliveryFailed) {
            // The refusal is expected; what it wrote is the assertion.
        }

        $written = implode("\n", array_map(
            static fn (MessageLogged $e): string => $e->message.' '.json_encode($e->context),
            $logged,
        ));

        self::assertNotSame('', $written, 'a delivery failure must leave a server-side trace');
        self::assertStringContainsString('otp.email.transport_failed', $written);
        self::assertStringNotContainsString(self::EMAIL, $written, 'an address reached the log');
        self::assertStringNotContainsString(self::CODE, $written, 'a passcode reached the log');
        self::assertStringNotContainsString('550', $written, 'the provider response reached the log');
        self::assertStringNotContainsString(
            'Recipient address rejected',
            $written,
            'the provider response reached the log',
        );
    }

    // ------------------------------------------------- fail-closed configuration

    /**
     * CARRIES WEIGHT. `null` is still what an unconfigured deployment gets.
     *
     * The driver is named rather than left to the environment, for the reason
     * the SMS driver tests give: a developer's own `.env` may select the real
     * one, and a test that asserted the ambient value would prove nothing
     * except what that machine happens to be set to.
     */
    public function test_the_refusing_sender_is_still_what_null_resolves_to(): void
    {
        config(['ridemate.email.driver' => 'null']);
        $this->app->forgetInstance(EmailSender::class);

        $sender = app(EmailSender::class);
        self::assertInstanceOf(NullEmailSender::class, $sender);

        $this->expectException(EmailDeliveryFailed::class);
        $sender->sendPasscode(self::EMAIL, self::CODE);
    }

    /** Selecting the real driver resolves the real adapter, through the container. */
    public function test_the_real_driver_resolves_the_laravel_mail_adapter(): void
    {
        config(['ridemate.email.driver' => 'laravel_mail']);
        $this->app->forgetInstance(EmailSender::class);

        self::assertInstanceOf(LaravelMailEmailSender::class, app(EmailSender::class));
    }

    /**
     * CARRIES WEIGHT. Production cannot sit on a mailer that records instead
     * of delivering.
     *
     * This is the sharpest failure in the chain because it does not error:
     * Laravel's stock `MAIL_MAILER` is `log`, which writes the passcode to a
     * file and reports success. Delivery appears to work, nobody receives
     * anything, and the only evidence is an absence. It is refused at
     * RESOLUTION, so a deployment stops at boot rather than at the first
     * member's attempt.
     */
    public function test_production_refuses_a_non_delivering_mailer(): void
    {
        foreach (['log', 'array'] as $mailer) {
            config(['ridemate.email.driver' => 'laravel_mail', 'mail.default' => $mailer]);
            $this->app->detectEnvironment(static fn (): string => 'production');
            $this->app->forgetInstance(EmailSender::class);

            try {
                app(EmailSender::class);
                self::fail("production resolved the sender on the $mailer mailer");
            } catch (RuntimeException $e) {
                self::assertStringContainsString('Refusing to deliver email', $e->getMessage());
            }
        }
    }

    /**
     * A mailer name config/mail.php does not define fails at RESOLUTION, not
     * at the first send.
     *
     * The framework does this one — `MailManager` refuses an undefined name
     * when the adapter's `Mailer` is injected — so the adapter carries no
     * guard of its own for it. The property is asserted here anyway, because
     * it is what stops a typo becoming an endpoint that accepts every passcode
     * request and delivers none of them. Environment-independent, which is why
     * this test names none.
     */
    public function test_an_undefined_mailer_is_refused_when_the_sender_resolves(): void
    {
        config(['ridemate.email.driver' => 'laravel_mail', 'mail.default' => 'zoho']);
        $this->app->forgetInstance(EmailSender::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Mailer [zoho] is not defined');

        app(EmailSender::class);
    }

    /** A real transport in production resolves, which is what makes the guard a guard. */
    public function test_production_accepts_a_delivering_mailer(): void
    {
        config(['ridemate.email.driver' => 'laravel_mail', 'mail.default' => 'smtp']);
        $this->app->detectEnvironment(static fn (): string => 'production');
        $this->app->forgetInstance(EmailSender::class);

        self::assertInstanceOf(LaravelMailEmailSender::class, app(EmailSender::class));
    }

    /**
     * Local and testing keep every convenience. The suite itself runs on the
     * `array` mailer, and a guard that refused there would be a guard nothing
     * could exercise.
     */
    public function test_a_non_production_environment_may_use_a_recording_mailer(): void
    {
        config(['ridemate.email.driver' => 'laravel_mail', 'mail.default' => 'array']);
        $this->app->forgetInstance(EmailSender::class);

        self::assertInstanceOf(LaravelMailEmailSender::class, app(EmailSender::class));
    }

    /**
     * A mailer whose every send throws, with a message shaped like the SMTP
     * dialogue a real provider returns.
     */
    private function failingMailer(string $response): Mailer
    {
        return new class($response) implements Mailer
        {
            public function __construct(private readonly string $response) {}

            public function to($users)
            {
                throw new TransportException($this->response);
            }

            public function cc($users)
            {
                throw new TransportException($this->response);
            }

            public function bcc($users)
            {
                throw new TransportException($this->response);
            }

            public function raw($text, $callback)
            {
                throw new TransportException($this->response);
            }

            /**
             * @param  Mailable|string|array<string, mixed>  $view
             * @param  array<string, mixed>  $data
             */
            public function send($view, array $data = [], $callback = null)
            {
                throw new TransportException($this->response);
            }

            /**
             * @param  Mailable|string|array<string, mixed>  $mailable
             * @param  array<string, mixed>  $data
             */
            public function sendNow($mailable, array $data = [], $callback = null)
            {
                throw new TransportException($this->response);
            }
        };
    }
}
