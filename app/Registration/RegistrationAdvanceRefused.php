<?php

declare(strict_types=1);

namespace App\Registration;

use RuntimeException;

/**
 * The registration domain refusing to advance a registration, and why.
 *
 * WHY THIS EXISTS RATHER THAN THE BARE `RuntimeException` IT REPLACES
 *
 * `RegistrationService::bind()` has always refused three things — a
 * registration that has gone, one that can no longer be advanced, and a second
 * destination on a channel that already names one. While nothing public called
 * it, one untyped exception per refusal was enough: the only reader was a test,
 * and a test can assert on a message.
 *
 * A public endpoint cannot. The three refusals are two different public
 * answers — a credential that stopped working is a `401`, and a channel already
 * bound to something else is a `409` a client can act on — and the only way to
 * tell them apart from an untyped `RuntimeException` is to match on its
 * message, which makes the wire contract a function of English prose. So the
 * reason becomes a value, exactly as `RegistrationCompletionRefused` already
 * carries one.
 *
 * SEPARATE FROM `RegistrationCompletionRefused`, SHARING ITS REASONS
 *
 * They are thrown by different transactions at different points, and neither
 * can produce the other's full set: binding cannot discover an account
 * collision, and completion cannot discover an unbound channel. One class
 * taking a union of reasons would be a class whose type says less than its
 * docblock. They share `RefusalReason` because the two cases they DO have in
 * common — a registration that has ended — is one fact with one name, and
 * duplicating it would be inviting two wire strings for it later.
 *
 * NO MESSAGE MAY NAME AN IDENTIFIER
 *
 * Not the address, not the number, not the credential, and not the destination
 * the registration is already bound to. These messages are developer-facing and
 * reach the exception renderer, which puts them in a response body in a debug
 * build — and a bound destination is a fact about an identity the caller has
 * just demonstrated it does not know.
 */
final class RegistrationAdvanceRefused extends RuntimeException
{
    private function __construct(
        public readonly RefusalReason $reason,
        string $message,
    ) {
        parent::__construct($message);
    }

    /**
     * Gone, expired, or already completed.
     *
     * One refusal for all three, for the reason `Registration::isAdvanceable()`
     * collapses them — and the same one `RegistrationService::resolve()` gives
     * as a bare null.
     */
    public static function registrationEnded(): self
    {
        return new self(
            RefusalReason::RegistrationEnded,
            'That registration can no longer be advanced.',
        );
    }

    public static function channelAlreadyBound(): self
    {
        return new self(
            RefusalReason::ChannelAlreadyBound,
            'This registration is already bound to a different destination on that channel.',
        );
    }
}
