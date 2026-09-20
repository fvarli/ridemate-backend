<?php

declare(strict_types=1);

namespace App\Otp\Email;

use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * The production adapter: a passcode becomes an ordinary Laravel mail message.
 *
 * WHY LARAVEL'S OWN MAIL STACK AND NOT A PROVIDER SDK
 *
 * The thing on the other side of this seam is SMTP, and Laravel already ships
 * the transport, the message builder and the configuration for it. An SDK
 * would add a dependency, a second credential shape and a second failure
 * vocabulary to gain nothing the framework does not already do. So the
 * provider is a `config/mail.php` mailer — today Zoho's EU SMTP endpoint —
 * and NOTHING about it appears in this class. Swapping endpoint, port,
 * transport or sender identity is a `MAIL_*` change; this file does not move.
 *
 * `config/ridemate.php`'s `email.driver` decides whether RideMate delivers
 * email at all; `config/mail.php` decides how. That separation is why the
 * refusing default survives: selecting `laravel_mail` is a deliberate act, and
 * until somebody performs it a stray `MAIL_MAILER` cannot route a passcode
 * anywhere.
 *
 * FAIL CLOSED ON A NON-DELIVERING MAILER
 *
 * The constructor refuses to exist in production on top of a mailer that
 * accepts a message and delivers nothing — `log` and `array`. Not the send
 * method, the constructor, for the reason `LocalEchoSmsSender` gives: a
 * misconfigured deployment fails at resolution rather than at the first
 * member's attempt. This is the sharpest failure in the whole chain because it
 * does not error — Laravel's stock `MAIL_MAILER` is `log`, which writes the
 * passcode to a file and reports success. Delivery appears to work, no member
 * receives anything, and the only evidence is an absence.
 *
 * A mailer name with no definition behind it needs no guard here: resolving
 * this class resolves a `Mailer`, and `MailManager` refuses an undefined name
 * at that point, in every environment. The framework already fails closed on a
 * typo, and restating it would be dead code with an unreachable message.
 *
 * WHAT A FAILURE IS ALLOWED TO SAY
 *
 * Nothing the transport said. A Symfony transport exception carries the SMTP
 * dialogue, and an SMTP rejection routinely quotes the recipient back — which
 * is the one string this whole surface refuses to disclose. So the message is
 * fixed text, the original is NOT chained (a chained `previous` reaches the
 * renderer in a debug build), and what reaches the log is the exception's
 * CLASS and the correlation id the request already shares. That is enough to
 * tell a transport fault from an application one; it is not enough to tell who
 * was being written to.
 */
final class LaravelMailEmailSender implements EmailSender
{
    /**
     * Transports that accept a message and deliver nothing.
     *
     * Both are legitimate outside production — the suite runs on `array` — and
     * both are catastrophic inside it, which is exactly the combination that
     * survives a deployment unnoticed.
     */
    private const NON_DELIVERING = ['log', 'array'];

    public function __construct(private readonly Mailer $mailer)
    {
        if (! App::environment('production')) {
            return;
        }

        // Defined by construction: the Mailer above could not have resolved
        // otherwise. What it resolved to is the open question.
        $name = (string) config('mail.default');
        $transport = (string) config("mail.mailers.$name.transport");

        if (in_array($transport, self::NON_DELIVERING, true)) {
            throw new RuntimeException(
                "Refusing to deliver email: MAIL_MAILER is \"$name\", a \"$transport\" transport that "
                .'records passcodes and delivers nothing. Configure a real transport, or leave '
                .'RIDEMATE_EMAIL_DRIVER unset.',
            );
        }
    }

    public function sendPasscode(string $emailAddress, string $code): void
    {
        try {
            $this->mailer->raw(
                $this->body($code),
                static function (Message $message) use ($emailAddress): void {
                    // The sender identity is config/mail.php's `from`, not a
                    // literal here: one deployment concern, one place.
                    $message->to($emailAddress)->subject('Your RideMate verification code');
                },
            );
        } catch (Throwable $e) {
            // The class, and nothing the transport wrote. See the class note.
            Log::warning('otp.email.transport_failed', ['transport_error' => $e::class]);

            throw new EmailDeliveryFailed('The passcode could not be handed to the mail transport.');
        }
    }

    /**
     * Minimal transactional text: who it is from, the code, how long it lives,
     * and what to do if it was not asked for.
     *
     * Plain text rather than a Blade view. This repository has no `resources/
     * views`, and a template would add a rendering path and a file for six
     * lines that carry a credential — the fewer places a live passcode is
     * interpolated, the better.
     *
     * The lifetime is read from `ridemate.otp.ttl` rather than written here.
     * A message that promised five minutes while the policy said ten would be
     * a lie the moment somebody tuned the policy.
     */
    private function body(string $code): string
    {
        return <<<TEXT
        RideMate

        Your verification code is:

            $code

        It expires in {$this->lifetime()}.

        If you did not ask for this code, ignore this message. Nobody can use it
        without it, and it stops working on its own.
        TEXT;
    }

    private function lifetime(): string
    {
        $seconds = (int) config('ridemate.otp.ttl');

        if ($seconds >= 60 && $seconds % 60 === 0) {
            $minutes = intdiv($seconds, 60);

            return $minutes === 1 ? '1 minute' : "$minutes minutes";
        }

        return $seconds === 1 ? '1 second' : "$seconds seconds";
    }
}
