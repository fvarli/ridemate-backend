<?php

declare(strict_types=1);

namespace App\Registration;

use App\Models\Registration;
use App\Otp\Email\EmailDeliveryFailed;
use App\Otp\Email\EmailSender;
use App\Otp\OtpChannel;
use App\Otp\OtpScope;
use App\Otp\OtpService;
use App\Otp\Sms\SmsDeliveryFailed;
use App\Otp\Sms\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Bind the destination to the registration, then send it a passcode that only
 * this registration can spend.
 *
 * WHY THIS DOES NOT DELEGATE TO `SendPasscode` OR `SendEmailPasscode`
 *
 * It did, and that was the bug. Those two issue into the STANDALONE scope — the
 * namespace `POST /auth/otp/verify` reads from — so a registration's SMS code
 * was a sign-in code, and two registrations naming one address shared the one
 * live challenge that address was allowed. The fix is not a check before
 * calling them; it is issuing into a different scope, and the scope is an
 * argument to `OtpService::issue()`. Passing it through those siblings would
 * have made the sign-in action take a registration's id, which is the coupling
 * their separation exists to avoid.
 *
 * What is duplicated here is eight lines of dispatch-and-log. What is NOT
 * duplicated is the part that matters: the issuing transaction, the advisory
 * lock, the policy read, the predecessor invalidation and the commit-before-
 * dispatch ordering all still live in `OtpService`, once.
 *
 * ONE ACTION TAKING A CHANNEL, WHERE THE OTP LAYER HAS TWO SIBLINGS
 *
 * Those two are siblings because they differ in what they normalize, which
 * sender they fail on and which exception a caller must catch. Here the first
 * is gone — normalization belongs to `RegistrationService::bind()`, which is
 * channel-dispatched already because the registration row has one column per
 * kind — and a second pair of actions would double the surface for the two that
 * remain. The delivery exceptions stay distinct and both propagate; a common
 * parent would be an abstraction invented for one call site.
 *
 * BIND FIRST, ALWAYS
 *
 * A challenge issued for a destination the registration does not name could
 * never be verified against it: `VerifyRegistrationPasscode` reads the
 * destination from the row and refuses to take one from a caller. So a send
 * that delivered first would be handing out a code for nothing.
 *
 * WHY BINDING IS NOT IN THE ISSUING TRANSACTION
 *
 * It commits first, on its own. The atomicity this slice owes is between
 * CONSUMPTION and PROOF, which is one transaction in
 * `VerifyRegistrationPasscode`. A crash after binding and before issuance
 * leaves a registration that names a destination and holds no challenge, which
 * is the same harmless state as one that was never sent to: the member asks
 * again. Widening the transaction to cover issuance would mean holding the
 * registration row across the destination-wide advisory lock, so a busy address
 * would block an unrelated registration's row.
 *
 * ITS PUBLIC CALLER, AND WHAT A DELIVERY FAILURE MAY SAY
 *
 * `POST /api/v1/registrations/otp` resolves this now. **Both delivery
 * exceptions still leave this method**, and that is deliberate: an action that
 * swallowed one would leave every other caller — and every test — unable to
 * tell a send that happened from one that did not.
 *
 * What the CONTROLLER does with `EmailDeliveryFailed` is a different question,
 * and it answers it by returning `202` anyway. A real SMTP transport rejects
 * an invalid, unroutable or suppressed recipient synchronously at submission;
 * letting that surface as a `500` while a deliverable address got `202` would
 * make the endpoint a mailbox-validity oracle, and — because suppression lists
 * are built from past bounces — partly a "has this address been used here
 * before" oracle. See `RequestRegistrationPasscodeController`.
 *
 * `SmsDeliveryFailed` still becomes a `500`, because no SMS provider has been
 * selected and the refusing sender distinguishes no recipient. There is no
 * oracle to close until one does.
 *
 * The warning below is what makes either failure findable. It is the whole
 * server-side record, and it names the challenge and nothing else.
 *
 * Email delivery is operational in production once `RIDEMATE_EMAIL_DRIVER` is
 * `laravel_mail` and `MAIL_*` names a real transport. SMS is not: that sender
 * still refuses, so mature registration still cannot be completed by a real
 * member.
 */
final class SendRegistrationPasscode
{
    public function __construct(
        private readonly RegistrationService $registrations,
        private readonly OtpService $otp,
        private readonly SmsSender $sms,
        private readonly EmailSender $email,
    ) {}

    /**
     * @throws InvalidIdentifier when the destination is not one of that kind.
     * @throws RegistrationAdvanceRefused when the registration has ended, or is
     *                                    already bound to a different destination
     *                                    on this channel.
     * @throws SmsDeliveryFailed|EmailDeliveryFailed after the challenge has committed.
     */
    public function __invoke(Registration $registration, OtpChannel $channel, string $destination): void
    {
        // A caller wrapping this in its own transaction would turn issue()'s
        // commit into a savepoint release, and delivery would then happen
        // before the outer commit — handing out a passcode for a row that might
        // still be rolled back. Checked before binding, so a refusal cannot
        // leave a destination named for a passcode that was never issued.
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException(
                'Passcode delivery must not run inside a database transaction.',
            );
        }

        $this->registrations->bind($registration, $channel, $destination);

        $canonical = $registration->destinationOn($channel);

        if ($canonical === null) {
            // Unreachable: bind() either wrote this column or threw. Stated
            // rather than assumed, because the value about to be handed to the
            // OTP layer has to be the canonical one and nothing else.
            throw new RuntimeException('The registration has no destination on that channel.');
        }

        // The scope is the whole point of the call. Issued here, this challenge
        // is findable only by this registration — not by another that named the
        // same address, and not by the sign-in path.
        $challenge = $this->otp->issue(
            $channel,
            $canonical,
            OtpScope::forRegistration($registration->id),
        );

        try {
            match ($channel) {
                OtpChannel::Sms => $this->sms->sendPasscode($challenge->destination, $challenge->code),
                OtpChannel::Email => $this->email->sendPasscode($challenge->destination, $challenge->code),
            };
        } catch (SmsDeliveryFailed|EmailDeliveryFailed $e) {
            // The challenge id and nothing else. Not the passcode, which would
            // put a credential in the log; not the destination, which is the
            // member's identity; not the registration, which is neither. The id
            // is enough to find the row.
            Log::warning('otp.delivery_failed', ['challenge_id' => $challenge->id]);

            throw $e;
        }
    }
}
