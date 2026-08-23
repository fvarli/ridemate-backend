<?php

declare(strict_types=1);

namespace App\Auth;

use Illuminate\Support\Str;

/**
 * The credential format: `{prefix}{token_id}.{secret}`.
 *
 * WHY THE ID IS IN THE TOKEN
 *
 * The alternative is looking a credential up BY its hash, which needs an index
 * on a 64-character column and turns every authenticated request into a search.
 * Carrying the row id makes validation a primary-key read, and the id is not a
 * secret: holding it grants nothing without the 256 bits beside it.
 *
 * WHY PARSING IS STRICT BEFORE THE DATABASE IS TOUCHED
 *
 * `parse()` rejects anything that is not exactly the expected shape — wrong
 * prefix, wrong arity, an id that is not a UUID, a secret with a character
 * outside the alphabet. That is not tidiness. An unvalidated id reaching a
 * query is how caller-supplied text ends up inside a driver error, and from
 * there inside a stack frame, a log line or a 500 body. The value never
 * becomes a bound parameter unless it could plausibly be real.
 *
 * Nothing here logs, and no method returns a secret in a message.
 */
final class TokenSecret
{
    /**
     * 32 bytes — 256 bits — rendered base64url so the credential stays a
     * single opaque word with no characters needing escaping anywhere it
     * travels.
     */
    public static function generate(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function compose(TokenKind $kind, string $id, string $secret): string
    {
        return $kind->prefix().$id.'.'.$secret;
    }

    public static function hash(TokenKind $kind, string $secret): string
    {
        return hash('sha256', $kind->hashDomain().$secret);
    }

    /**
     * The shape check. Null means "this cannot be a credential of this kind",
     * with no further distinction — a caller learns nothing from which rule
     * rejected them.
     */
    public static function parse(TokenKind $kind, string $candidate): ?ParsedToken
    {
        $prefix = $kind->prefix();

        if (! str_starts_with($candidate, $prefix)) {
            return null;
        }

        $body = substr($candidate, strlen($prefix));

        // Exactly one separator. The secret's alphabet excludes '.', so a
        // second one means the value was tampered with rather than truncated.
        if (substr_count($body, '.') !== 1) {
            return null;
        }

        [$id, $secret] = explode('.', $body, 2);

        if (! Str::isUuid($id)) {
            return null;
        }

        if ($secret === '' || preg_match('/^[A-Za-z0-9_-]+$/', $secret) !== 1) {
            return null;
        }

        return new ParsedToken($id, $secret);
    }

    /**
     * Constant-time comparison.
     *
     * Both values are hex digests of the same length, so the timing signal is
     * small — but "small" is an argument for not measuring it rather than for
     * leaving it there, and hash_equals costs nothing.
     */
    public static function matches(string $storedHash, TokenKind $kind, string $secret): bool
    {
        return hash_equals($storedHash, self::hash($kind, $secret));
    }
}
