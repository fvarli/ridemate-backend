<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Validator;

/**
 * Turns whatever a member typed into the one string RideMate challenges.
 *
 * The email counterpart of `PhoneNumber`, and it exists for the same reason:
 * `OtpService` assumes its destination arrives ALREADY CANONICAL, and three
 * separate identity constructs depend on that being exactly one string — the
 * partial unique index on `(channel, destination)`, the advisory lock key, and
 * the HMAC the passcode is stored under. Two spellings of one address would be
 * two independent challenges with two independent budgets.
 *
 * WHY THIS IS NOT AN RFC PARSER
 *
 * Email syntax is not a regular expression and it is not a short function
 * either. `egulias/email-validator` already ships with the framework and is
 * what Laravel's own `email` rule delegates to, so validation goes through the
 * rule rather than through a second, subtly different opinion about what an
 * address is.
 *
 * `strict` — `NoRFCWarningsValidation` — rather than the looser default, and
 * the reason is load-bearing rather than fastidious. The default accepts
 * quoted local parts (`"a b"@example.com`), comments, bare hostnames with no
 * TLD and IP-address literals. A quoted local part is the one place where case
 * genuinely carries meaning, so lowercasing it would change the address rather
 * than canonicalize it. Refusing those shapes is what makes the lowercase rule
 * below safe to apply to the WHOLE string.
 *
 * WHAT IS DELIBERATELY NOT DONE
 *
 * No dot removal, no `+tag` stripping, no other provider-specific folding.
 * `a.li@`, `ali+rides@` and `ali@` are three different addresses here, because
 * whether they reach one mailbox is a fact about one provider's routing and
 * not a fact about email. Guessing wrong merges two members into one identity.
 *
 * No MX or DNS lookup: it is a network call on a path that must stay fast and
 * offline, it answers a deliverability question rather than an identity one,
 * and its timing would be observable.
 *
 * No IDN/punycode conversion and no SMTPUTF8 normalization. An
 * internationalized address is accepted as written and lowercased, so
 * `exämple.com` and `xn--exmple-cua.com` remain two destinations. That is the
 * honest consequence of not transforming: converting between them is a policy
 * with edge cases nobody here has decided, and inventing one quietly would be
 * worse than two members having to type the address the same way twice.
 *
 * Nothing in this class logs. An email address is the member's identity, and
 * Phase 8's logging rule is an allowlist of scalars that does not include it.
 */
final class EmailAddress
{
    /**
     * RFC 5321's practical ceiling for a whole address.
     *
     * Also the cap that keeps a canonical destination inside the column it is
     * written to: `otp_challenges.destination` is `varchar(255)`, and
     * PostgreSQL counts that in CHARACTERS, so a 254-character address fits
     * however many bytes its encoding takes. The validator alone does not
     * guarantee this — it enforces the local-part and domain limits
     * separately, whose sum exceeds 255.
     */
    private const MAX_LENGTH = 254;

    /**
     * The canonical form, or null if this is not an address a passcode can be
     * sent to.
     *
     * Null rather than an exception, as `PhoneNumber` does: the caller is
     * validating, and "the member typed something wrong" is ordinary.
     */
    public static function normalize(string $input): ?string
    {
        // Before trimming, and deliberately: a trailing newline IS surrounding
        // whitespace and trim would remove it, which would quietly accept a
        // value that arrived carrying a line break. An address is about to be
        // handed to a mail adapter that composes headers from it, so the
        // answer to a carriage return anywhere in the input is no.
        if (preg_match('/[\r\n]/', $input) === 1) {
            return null;
        }

        $trimmed = trim($input);

        if ($trimmed === '') {
            return null;
        }

        // Lowercased BEFORE validation, so the string that was checked is
        // exactly the string that gets stored. Validating first and folding
        // afterwards would leave a gap — small today, and the kind of gap that
        // stops being small once anything else is added between the two.
        //
        // The whole address, not just the domain: RFC 5321 makes the local
        // part case-SENSITIVE, and essentially no provider treats it that way.
        // One identity per mailbox is worth more than a standards edge case
        // nobody can actually use, but it is a product decision rather than a
        // standards one, so it is written down here.
        //
        // mb_strtolower rather than strtolower: since PHP 8.2 the latter is
        // ASCII-only, which would leave `Äli@` and `äli@` as two identities
        // and make the rule above true only for part of the alphabet.
        $canonical = mb_strtolower($trimmed, 'UTF-8');

        if (mb_strlen($canonical) > self::MAX_LENGTH) {
            return null;
        }

        return self::isSyntacticallyValid($canonical) ? $canonical : null;
    }

    /** Whether this input is an address at all. */
    public static function isValid(string $input): bool
    {
        return self::normalize($input) !== null;
    }

    /**
     * The framework's definition of a valid address, not a second one.
     */
    private static function isSyntacticallyValid(string $address): bool
    {
        return Validator::make(
            ['email' => $address],
            ['email' => 'email:strict'],
        )->passes();
    }
}
