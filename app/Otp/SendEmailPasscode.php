<?php

declare(strict_types=1);

namespace App\Otp;

use App\Otp\Email\EmailDeliveryFailed;
use App\Otp\Email\EmailSender;
use App\Otp\Email\InvalidEmailAddress;
use App\Support\EmailAddress;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Issue an email passcode, then deliver it. In that order, and never the
 * reverse.
 *
 * The sibling of `SendPasscode`, which does the same for SMS. A sibling rather
 * than a channel parameter on that one: the two differ in what they normalize,
 * which sender they fail on and which exception a caller must catch, and a
 * single action taking a channel would hide all three behind an argument. The
 * shared part — the ordering below — is not duplicated, because it lives in
 * `OtpService` and in the transaction guard, not here.
 *
 * NOT REACHABLE FROM OUTSIDE
 *
 * No route resolves this and no controller calls it. It is an internal
 * application capability: something that can prove possession of an email
 * address, written and tested before the slice that decides what proving it
 * entitles anyone to. In production the bound `EmailSender` is the refusing
 * one, so a caller that appeared today would fail closed rather than quietly
 * do nothing.
 *
 * WHY DELIVERY IS OUTSIDE THE TRANSACTION, AND WHY A FAILURE KEEPS THE ROW
 *
 * Both for the reasons `SendPasscode` gives, and they are not weaker for
 * email. An email API is still somebody else's network service, so holding row
 * locks across it is still holding them for as long as that service feels
 * like. And a passcode delivered against a rolled-back row would still be a
 * credential nothing recorded — worse than a recorded one that never arrived,
 * which merely expires. The member retries after the cooldown, and the
 * cooldown genuinely applies, because the row is real.
 */
final class SendEmailPasscode
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly EmailSender $email,
    ) {}

    /**
     * @throws InvalidEmailAddress before anything is issued.
     * @throws EmailDeliveryFailed after the challenge has committed.
     */
    public function __invoke(string $emailAddress): void
    {
        // A caller wrapping this in its own transaction would turn issue()'s
        // commit into a savepoint release, and delivery would then happen
        // before the outer commit — handing out a passcode for a row that
        // might still be rolled back.
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException(
                'Passcode delivery must not run inside a database transaction.',
            );
        }

        // Before issuance, so a malformed address cannot spend an hourly
        // budget or start a cooldown — and so OtpService receives the one
        // canonical string its lock, its unique index and its HMAC all assume.
        $canonical = EmailAddress::normalize($emailAddress)
            ?? throw new InvalidEmailAddress('The destination is not a valid email address.');

        $challenge = $this->otp->issue(OtpChannel::Email, $canonical, OtpScope::standalone());

        try {
            $this->email->sendPasscode($challenge->destination, $challenge->code);
        } catch (EmailDeliveryFailed $e) {
            // The challenge id and nothing else. Not the passcode, which would
            // put a credential in the log; not the address, which is the
            // member's identity. The id is enough to find the row.
            Log::warning('otp.delivery_failed', ['challenge_id' => $challenge->id]);

            throw $e;
        }
    }
}
