<?php

declare(strict_types=1);

namespace App\Otp\Email;

/**
 * The seam an email provider will slot into.
 *
 * NOTHING CALLS THIS YET
 *
 * `OtpChannel::Email` is stored and isolated, but no code path issues a
 * challenge on it: there is no email endpoint, no email column on an account
 * and no caller of this interface outside its own tests. Binding an
 * implementation in the container does not make Email OTP reachable, and this
 * seam exists so that the day one is written, the provider decision is the
 * only thing left to make.
 *
 * DELIBERATELY NARROW, AND DELIBERATELY PROVIDER-NEUTRAL
 *
 * One method, for one purpose — the same shape as `App\Otp\Sms\SmsSender`, and
 * for the same reason: a general "send any email" port would be speculative,
 * and transactional product mail has a different bar from an authentication
 * code and should state its own requirements rather than inherit these.
 *
 * Nothing a provider cares about appears here. No subject, template, sender
 * identity, reply-to, message id or mailer name: each is a provider's answer
 * to a question the domain does not ask. The domain knows a recipient and a
 * code; an adapter decides how that becomes a message.
 *
 * Implementations will be called AFTER the issuing transaction has committed,
 * as the SMS ones are. None may assume a transaction is open.
 */
interface EmailSender
{
    /**
     * @throws EmailDeliveryFailed when the passcode could not be handed off.
     */
    public function sendPasscode(string $emailAddress, string $code): void;
}
