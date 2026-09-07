<?php

declare(strict_types=1);

namespace App\Profiles;

use InvalidArgumentException;

/**
 * What a member calls themselves, and the initials that follow from it.
 *
 * THE AUTHORITATIVE IMPLEMENTATION
 *
 * Initials are derived here and nowhere else. They are not stored, because a
 * stored copy can disagree with the name beside it, and a profile rendering the
 * wrong two letters is a small, constant, visible lie. Phase 12's driver
 * projection reuses this class rather than deriving them a second time — two
 * implementations of a deterministic rule eventually stop agreeing, and only
 * one of them gets fixed.
 *
 * CHARACTERS, NOT BYTES
 *
 * `Ayşe` is four characters and five bytes. A byte-counting limit would reject
 * names that are well inside it and accept none of the ones it meant to, so
 * every length here is `mb_*`.
 *
 * TURKISH CASING, DELIBERATELY NOT `mb_strtoupper` ALONE
 *
 * Unicode's default uppercasing maps `i` to `I`, so `irem` yields `I` where
 * Turkish requires `İ`; dotless `ı` uppercases to `I` in both. The pilot is
 * İstanbul and the client already made this decision explicitly — see
 * RmTextConventions.upperTr in the Flutter repository — so the two ends agree
 * on what a member's initials look like. The mapping is applied before
 * uppercasing rather than being read from a locale setting: there is one pilot
 * and one rule, and a configuration knob nobody turns is an abstraction
 * pretending to be a decision.
 */
final readonly class DisplayName
{
    /** The contract's limit, in characters. */
    public const MAX_LENGTH = 80;

    private function __construct(public string $value) {}

    /**
     * @throws InvalidArgumentException when the name is empty or too long.
     */
    public static function fromInput(string $input): self
    {
        // Trimmed first, so a name is judged by what it actually says. A field
        // holding only spaces is empty, however many of them there are — and
        // the trimmed form is what gets stored, so validation and persistence
        // can never disagree about which string was checked.
        $trimmed = self::trim($input);

        if ($trimmed === '') {
            throw new InvalidArgumentException('A display name cannot be empty.');
        }

        if (mb_strlen($trimmed) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('A display name is at most 80 characters.');
        }

        return new self($trimmed);
    }

    /**
     * The member's initials.
     *
     * One token gives one letter; two or more give the first token's first
     * character and the last token's first — never the middle ones, so
     * `Ayşe Nur Demir` is `AD` rather than `AND`. Any run of whitespace
     * separates tokens, because a name pasted from elsewhere may carry more
     * than one space and that is not a different person.
     */
    public function initials(): string
    {
        $tokens = self::tokenize($this->value);

        // `fromInput` guarantees a non-empty trimmed value, so there is always
        // at least one token by construction.
        $first = self::firstCharacter($tokens[0]);

        if (count($tokens) === 1) {
            return self::upperTr($first);
        }

        $last = self::firstCharacter($tokens[count($tokens) - 1]);

        return self::upperTr($first.$last);
    }

    /**
     * Turkish-aware uppercase.
     *
     * `i` becomes dotted `İ` and `ı` becomes dotless `I`; everything else is
     * left to Unicode. Applied before `mb_strtoupper` so the two mappings
     * cannot be undone by it.
     */
    public static function upperTr(string $input): string
    {
        return mb_strtoupper(
            str_replace(['i', 'ı'], ['İ', 'I'], $input),
            'UTF-8',
        );
    }

    /**
     * Whitespace removed from both ends, including the Unicode kinds.
     *
     * PHP's `trim` only knows the ASCII set, so a name wrapped in a non-breaking
     * space would survive it and read as non-empty while displaying as blank.
     */
    private static function trim(string $input): string
    {
        return (string) preg_replace('/^\s+|\s+$/u', '', $input);
    }

    /**
     * @return list<string> at least one element, given a non-empty input.
     */
    private static function tokenize(string $value): array
    {
        /** @var list<string> $tokens */
        $tokens = preg_split('/\s+/u', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return $tokens;
    }

    private static function firstCharacter(string $token): string
    {
        return mb_substr($token, 0, 1);
    }
}
