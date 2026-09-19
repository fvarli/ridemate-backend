<?php

declare(strict_types=1);

namespace App\Registration;

use Illuminate\Support\Str;

/**
 * The pre-account credential format: `rmreg_{registration_id}.{secret}`.
 *
 * WHAT IT IS, AND THE FOUR THINGS IT IS NOT
 *
 * It authorizes exactly one thing: advancing the registration it names. It is
 * not an access token, not a refresh token, not a session, and not evidence
 * that any account exists. The holder of one is not authenticated, and there
 * is no state in which presenting it at an authenticated endpoint does
 * anything — `AuthenticateToken` resolves `rma_` against `auth_tokens`, and a
 * value starting `rmreg_` cannot parse there at all.
 *
 * WHY THIS IS NOT `App\Auth\TokenSecret` WITH A THIRD `TokenKind`
 *
 * That was the first design and it is the wrong one. `TokenKind` is named, in
 * its own docblock, as "the two credentials" that share a row and are minted by
 * one act; a registration credential shares neither. Joining that enum would
 * make every method there take a value it must never be given, and would put
 * the one credential that must NOT open a session inside the class whose whole
 * job is opening them. The cost of standing apart is small and visible: the
 * only genuinely kind-free primitive there is the entropy line, which is one
 * call to `random_bytes` and the thing least likely to drift.
 *
 * WHY THE PREFIX IS LONGER THAN ITS NEIGHBOURS
 *
 * `rma_` and `rmr_` are four characters and a pair; this is six and is not one
 * of them. A prefix exists so a leaked value is identifiable in a bug report
 * without anyone testing it, and the most important thing to identify about
 * this one is that it is not an auth token.
 *
 * WHY PARSING IS STRICT BEFORE THE DATABASE IS TOUCHED
 *
 * For the reason `TokenSecret` gives: an unvalidated id reaching a query is how
 * caller-supplied text ends up inside a driver error, and from there inside a
 * stack frame, a log line or a 500 body. The value becomes a bound parameter
 * only once it could plausibly be real.
 *
 * Nothing here logs, and no method returns a secret in a message.
 */
final class RegistrationSecret
{
    /**
     * Not one of the auth prefixes, and deliberately not shaped like one.
     */
    private const PREFIX = 'rmreg_';

    /**
     * Distinct from `rm.access.v1:` and `rm.refresh.v1:`, and that is the real
     * separation rather than the prefix.
     *
     * Hashing under its own domain means a registration secret cannot validate
     * as an access or refresh token, and neither can validate here, even if
     * every prefix check in the application were deleted — the stored digests
     * simply do not match. One is a convenience; this is a guarantee.
     *
     * `.v1` is deliberate: changing the construction later means changing this
     * string, which invalidates every credential in flight at once rather than
     * leaving two constructions quietly accepted.
     */
    private const HASH_DOMAIN = 'rm.registration.v1:';

    /**
     * 32 bytes — 256 bits — rendered base64url so the credential stays a single
     * opaque word with no characters needing escaping anywhere it travels.
     *
     * `random_bytes`, not `rand` or `mt_rand`: this is a credential.
     */
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function compose(string $registrationId, string $secret): string
    {
        return self::PREFIX.$registrationId.'.'.$secret;
    }

    public static function hash(string $secret): string
    {
        return hash('sha256', self::HASH_DOMAIN.$secret);
    }

    /**
     * The shape check.
     *
     * Null means "this cannot be a registration credential", with no further
     * distinction — a caller learns nothing from which rule rejected them.
     */
    public static function parse(string $candidate): ?ParsedCredential
    {
        if (! str_starts_with($candidate, self::PREFIX)) {
            return null;
        }

        $body = substr($candidate, strlen(self::PREFIX));

        // Exactly one separator. The secret's alphabet excludes '.', so a
        // second one means the value was tampered with rather than truncated.
        if (substr_count($body, '.') !== 1) {
            return null;
        }

        [$registrationId, $secret] = explode('.', $body, 2);

        if (! Str::isUuid($registrationId)) {
            return null;
        }

        if ($secret === '' || preg_match('/^[A-Za-z0-9_-]+$/', $secret) !== 1) {
            return null;
        }

        return new ParsedCredential($registrationId, $secret);
    }

    /**
     * Constant-time comparison.
     *
     * Both values are hex digests of the same length, so the timing signal is
     * small — but "small" is an argument for not measuring it rather than for
     * leaving it there, and `hash_equals` costs nothing.
     */
    public static function matches(string $storedHash, string $secret): bool
    {
        return hash_equals($storedHash, self::hash($secret));
    }
}
