<?php

declare(strict_types=1);

namespace App\Registration;

use App\Models\Registration;
use App\Otp\Email\EmailDeliveryFailed;
use App\Otp\OtpChannel;
use App\Otp\SendEmailPasscode;
use App\Otp\SendPasscode;
use App\Otp\Sms\SmsDeliveryFailed;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Bind the destination to the registration, then send it a passcode.
 *
 * In that order, and the order is the point. A challenge issued for a
 * destination the registration does not name could never be verified against
 * it — `VerifyRegistrationPasscode` reads the destination from the row and
 * refuses to take one from a caller — so a send that delivered first would be
 * handing out a code for nothing.
 *
 * ONE ACTION TAKING A CHANNEL, WHERE THE OTP LAYER HAS TWO SIBLINGS
 *
 * `SendPasscode` and `SendEmailPasscode` are siblings because they differ in
 * what they normalize, which sender they fail on and which exception a caller
 * must catch. Here the first of those is gone: normalization belongs to
 * `RegistrationService::bind()`, which is channel-dispatched already because
 * the registration row has one column per kind. What is left is which sibling
 * to delegate to — and delegating is exactly what this does, so neither the
 * issuance ordering, the post-commit dispatch nor the delivery logging is
 * written a second time here.
 *
 * The two delivery exceptions stay distinct and both propagate. A common
 * parent would be an abstraction invented for one call site.
 *
 * NOT REACHABLE FROM OUTSIDE
 *
 * No route resolves this and no controller calls it. In production both bound
 * senders refuse — no SMS provider and no email provider has been selected —
 * so a caller that appeared today would fail closed on either channel.
 *
 * WHY BINDING IS NOT IN THE ISSUING TRANSACTION
 *
 * It commits first, on its own. The atomicity this slice owes is between
 * CONSUMPTION and PROOF, which is one transaction in
 * `VerifyRegistrationPasscode`. A crash after binding and before issuance
 * leaves a registration that names a destination and holds no challenge, which
 * is the same harmless state as one that was never sent to: the member asks
 * again. Widening the transaction to cover issuance would mean holding the
 * registration row across `OtpService::issue()`'s advisory lock, and then a
 * busy destination would block an unrelated registration's row.
 */
final class SendRegistrationPasscode
{
    public function __construct(
        private readonly RegistrationService $registrations,
        private readonly SendPasscode $sms,
        private readonly SendEmailPasscode $email,
    ) {}

    /**
     * @throws InvalidIdentifier when the destination is not one of that kind.
     * @throws RuntimeException when the registration has ended, or is already
     *                          bound to a different destination on this channel.
     * @throws SmsDeliveryFailed|EmailDeliveryFailed after the challenge has committed.
     */
    public function __invoke(Registration $registration, OtpChannel $channel, string $destination): void
    {
        // Checked here as well as inside the senders, and earlier than they
        // would: a caller wrapping this in a transaction would otherwise get as
        // far as binding — a committed write, from its point of view — before
        // the sender refused, leaving a destination bound for a passcode that
        // was never issued.
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

        match ($channel) {
            OtpChannel::Sms => ($this->sms)($canonical),
            OtpChannel::Email => ($this->email)($canonical),
        };
    }
}
