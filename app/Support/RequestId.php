<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * The correlation identifier for one HTTP request.
 *
 * NOT a domain identifier. Entities get UUIDv7 primary keys from Phase 9; this
 * is a transport concern that exists only for the lifetime of a request and is
 * never stored against anything. The header name keeps the two apart.
 *
 * An inbound X-Request-Id is honoured so a caller can correlate across a
 * retry — but only if it is a well-formed UUID. Echoing arbitrary client input
 * into the log stream would let anyone forge, pollute or inject log lines, and
 * an unbounded value would be a denial-of-service on the log volume.
 */
final class RequestId
{
    public const HEADER = 'X-Request-Id';

    private const ATTRIBUTE = 'rm.request_id';

    /**
     * A canonical RFC 4122 UUID in any version.
     */
    private const PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i';

    /**
     * Resolves the id for this request, generating one when none is usable.
     */
    public static function resolve(Request $request): string
    {
        $existing = $request->attributes->get(self::ATTRIBUTE);

        if (is_string($existing) && $existing !== '') {
            return $existing;
        }

        $inbound = $request->headers->get(self::HEADER);

        $id = is_string($inbound) && preg_match(self::PATTERN, $inbound) === 1
            ? strtolower($inbound)
            // Time-ordered, so log lines sort by arrival without a timestamp join.
            : Str::uuid7()->toString();

        $request->attributes->set(self::ATTRIBUTE, $id);

        return $id;
    }

    /**
     * The id already resolved for this request.
     */
    public static function get(Request $request): string
    {
        return self::resolve($request);
    }
}
