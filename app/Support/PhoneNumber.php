<?php

declare(strict_types=1);

namespace App\Support;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/**
 * Turns whatever a member typed into the one string RideMate stores.
 *
 * WHY THIS IS NOT A REGULAR EXPRESSION
 *
 * `0532 123 45 67`, `+90 532 123 45 67`, `905321234567` and `0090 532 1234567`
 * are one person. Store them as typed and that person has four accounts, four
 * trust histories and four sets of trips — and no way to merge them, because by
 * the time anyone notices, each has real data hanging off it.
 *
 * The tempting shortcut is a `+90`-shaped regular expression. It is wrong in
 * two directions at once. It accepts numbers that cannot exist, because digit
 * count alone does not tell you whether `+90 999 ...` is a real Turkish range.
 * And it rejects numbers that do, because RideMate ships an English locale and
 * a tester with a foreign SIM is a person we want, not a malformed input.
 *
 * So the real thing does the work. libphonenumber carries Google's per-country
 * metadata, which is the only place the answer actually lives.
 *
 * THE BOUNDARY
 *
 * Normalization happens ONCE, here, at the edge — before a value reaches a
 * query, a challenge or a row. Everything downstream may assume `phone_e164`
 * is canonical, which is what makes `unique(phone_e164)` a real identity
 * constraint rather than a constraint on formatting.
 *
 * Nothing in this class logs. A phone number is the member's identity and
 * Phase 8's logging rule is an allowlist of scalars that does not include it.
 */
final class PhoneNumber
{
    /**
     * The canonical E.164 form, or null if this is not a real phone number.
     *
     * Null rather than an exception because the caller is validation, and
     * "the member typed something wrong" is an ordinary outcome rather than
     * an exceptional one.
     */
    public static function normalize(string $input): ?string
    {
        $input = trim($input);

        if ($input === '') {
            return null;
        }

        $util = PhoneNumberUtil::getInstance();

        try {
            // The region only interprets numbers given WITHOUT a country code:
            // a bare `0532...` is Turkish because the pilot is Istanbul. A
            // number in full international form ignores it entirely, which is
            // what keeps this a parsing default rather than a restriction.
            $parsed = $util->parse($input, self::defaultRegion());
        } catch (NumberParseException) {
            // Too short, too long, letters, punctuation that resolves to
            // nothing, a country code that does not exist. All the same answer.
            return null;
        }

        // Parsing succeeds for shapes that cannot be dialled — the right number
        // of digits in a range no carrier owns. Validity is a separate question
        // from syntax, and it is the one that matters.
        if (! $util->isValidNumber($parsed)) {
            return null;
        }

        return $util->format($parsed, PhoneNumberFormat::E164);
    }

    /** Whether this input is a real phone number at all. */
    public static function isValid(string $input): bool
    {
        return self::normalize($input) !== null;
    }

    private static function defaultRegion(): string
    {
        $region = config('ridemate.phone.default_region');

        return is_string($region) && $region !== '' ? $region : 'TR';
    }
}
