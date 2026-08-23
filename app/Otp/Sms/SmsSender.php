<?php

declare(strict_types=1);

namespace App\Otp\Sms;

/**
 * The seam a production SMS provider will slot into.
 *
 * Deliberately narrow: one method, for one purpose. A general "send any SMS"
 * port would be speculative today, and the safety channel — which has the
 * harder reliability bar and should lead provider selection — will state its
 * own requirements rather than inherit this one's.
 *
 * Implementations are called AFTER the issuing transaction has committed. None
 * of them may assume a transaction is open, and none may be slow enough to
 * matter inside one, because none of them runs inside one.
 */
interface SmsSender
{
    /**
     * @throws SmsDeliveryFailed when the passcode could not be handed off.
     */
    public function sendPasscode(string $phoneE164, string $code): void;
}
