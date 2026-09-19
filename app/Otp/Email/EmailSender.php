<?php

declare(strict_types=1);

namespace App\Otp\Email;

/**
 * The seam an email provider will slot into.
 *
 * ONE CALLER, AND IT IS NOT PUBLIC
 *
 * `SendEmailPasscode` calls this, after its challenge has committed. Nothing
 * public calls `SendEmailPasscode`: there is no email endpoint and no email
 * column on an account. So the capability is real and the provider decision is
 * still the only thing left to make — the default implementation refuses, and
 * in production that is the one bound.
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
