<?php

declare(strict_types=1);

namespace App\Otp;

use App\Otp\Sms\SmsDeliveryFailed;
use App\Otp\Sms\SmsSender;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Issue a passcode, then deliver it. In that order, and never the reverse.
 *
 * WHY THIS IS AN ACTION RATHER THAN CONTROLLER CODE
 *
 * The ordering below is a correctness property, not request handling, and it
 * needs to be testable without an HTTP surface — which does not exist yet,
 * because the contract commit comes first. Putting it here also means the
 * controller cannot get it wrong by accident later.
 *
 * WHY DELIVERY IS OUTSIDE THE TRANSACTION
 *
 * An SMS provider is a network call to someone else's service. Holding a
 * database transaction open across one means a slow provider holds row locks
 * and a connection for as long as it feels like, and a hung provider holds
 * them indefinitely. OtpService::issue() commits before it returns; this
 * dispatches afterwards.
 *
 * WHY A FAILED DELIVERY DOES NOT ROLL THE CHALLENGE BACK
 *
 * It cannot: the transaction is already committed. That is the intended
 * behaviour rather than a limitation. A provider that fails after handing the
 * message off would leave a member holding a passcode with no matching row —
 * strictly worse than a row whose passcode never arrived, which merely expires
 * in five minutes. The member retries after the cooldown, and the cooldown
 * genuinely applies, because the row is real.
 */
final class SendPasscode
{
    public function __construct(
        private readonly OtpService $otp,
        private readonly SmsSender $sms,
    ) {}

    public function __invoke(string $phoneE164): void
    {
        // A future caller wrapping this in its own transaction would turn
        // issue()'s commit into a savepoint release, and delivery would then
        // happen before the outer commit — handing out a passcode for a row
        // that might still be rolled back. Cheap to check, and the failure it
        // prevents is one nobody would find by reading the code.
        if (DB::transactionLevel() > 0) {
            throw new RuntimeException(
                'Passcode delivery must not run inside a database transaction.',
            );
        }

        $challenge = $this->otp->issue($phoneE164);

        try {
            $this->sms->sendPasscode($challenge->phoneE164, $challenge->code);
        } catch (SmsDeliveryFailed $e) {
            // The challenge id and nothing else. Not the passcode, which would
            // put a credential in the log; not the number, which is the
            // member's identity. The id is enough to find the row.
            Log::warning('otp.delivery_failed', ['challenge_id' => $challenge->id]);

            throw $e;
        }
    }
}
