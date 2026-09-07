<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Profiles\DisplayName;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The one place initials are decided.
 *
 * These are the rules Phase 12's driver projection will read through, so they
 * are pinned here rather than left to whatever the first caller happens to
 * need. A second implementation of a deterministic rule eventually disagrees
 * with the first, and only one of them gets fixed.
 */
final class DisplayNameTest extends TestCase
{
    public function test_surrounding_whitespace_is_removed(): void
    {
        self::assertSame('Ayşe Demir', DisplayName::fromInput('  Ayşe Demir  ')->value);
    }

    /**
     * Unicode whitespace too, not just the ASCII kinds PHP's trim knows.
     *
     * A name wrapped in non-breaking spaces would otherwise pass the empty
     * check and then render as a blank profile.
     */
    public function test_unicode_whitespace_is_removed(): void
    {
        self::assertSame('Ayşe', DisplayName::fromInput("\u{00A0}Ayşe\u{00A0}")->value);
    }

    public function test_a_single_character_is_a_name(): void
    {
        self::assertSame('A', DisplayName::fromInput('A')->value);
    }

    public function test_eighty_characters_are_accepted(): void
    {
        $name = str_repeat('ş', 80);

        self::assertSame($name, DisplayName::fromInput($name)->value);
    }

    /**
     * CARRIES WEIGHT. Characters, not bytes.
     *
     * These 80 characters are 160 bytes. A byte-counting limit would reject a
     * name that is exactly at the published maximum — and would do it only for
     * members whose names are not ASCII, which is most of them here.
     */
    public function test_the_limit_counts_characters_rather_than_bytes(): void
    {
        $name = str_repeat('ş', 80);

        self::assertSame(160, strlen($name));
        self::assertSame(80, mb_strlen($name));
        self::assertSame($name, DisplayName::fromInput($name)->value);
    }

    public function test_eighty_one_characters_are_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DisplayName::fromInput(str_repeat('a', 81));
    }

    public function test_an_empty_name_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DisplayName::fromInput('');
    }

    public function test_a_name_of_only_whitespace_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        DisplayName::fromInput("   \t \u{00A0} ");
    }

    /**
     * The length is measured AFTER trimming, so padding cannot push a
     * legitimate name over the limit.
     */
    public function test_length_is_measured_after_trimming(): void
    {
        $name = str_repeat('a', 80);

        self::assertSame($name, DisplayName::fromInput('   '.$name.'   ')->value);
    }

    #[DataProvider('initialsCases')]
    public function test_initials(string $input, string $expected): void
    {
        self::assertSame($expected, DisplayName::fromInput($input)->initials());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function initialsCases(): array
    {
        return [
            'one token gives one letter' => ['Ayşe', 'A'],
            'two tokens give first and last' => ['Ayşe Demir', 'AD'],
            // Never the middle ones. Three tokens still give two letters.
            'three tokens skip the middle' => ['Ayşe Nur Demir', 'AD'],
            'four tokens still give two' => ['Ali Can Nur Demir', 'AD'],
            'repeated whitespace is one separator' => ['Ayşe    Demir', 'AD'],
            'already uppercase stays' => ['AYŞE DEMİR', 'AD'],
            'surrounding whitespace is irrelevant' => ['  Ayşe Demir  ', 'AD'],
        ];
    }

    /**
     * CARRIES WEIGHT. Turkish casing, which Unicode's default gets wrong.
     *
     * `mb_strtoupper('i')` is `I`. In Turkish the dotted `i` uppercases to the
     * dotted `İ`, and only the dotless `ı` becomes `I`. The pilot is İstanbul
     * and the client already made this decision explicitly, so the two ends
     * have to agree about what a member's initials look like.
     */
    #[DataProvider('turkishCasingCases')]
    public function test_turkish_casing(string $input, string $expected): void
    {
        self::assertSame($expected, DisplayName::fromInput($input)->initials());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function turkishCasingCases(): array
    {
        return [
            // The case the whole rule exists for.
            'dotted i becomes dotted İ' => ['irem yılmaz', 'İY'],
            'dotless ı becomes I' => ['ırmak deniz', 'ID'],
            'both in one name' => ['irem ırmak', 'İI'],
            'single dotted token' => ['irem', 'İ'],
            'single dotless token' => ['ırmak', 'I'],
            'already correct is unchanged' => ['İrem Yılmaz', 'İY'],
        ];
    }

    /**
     * The mistake this rule replaces, stated so nobody reintroduces it by
     * reaching for the obvious function.
     */
    public function test_plain_unicode_uppercasing_would_be_wrong(): void
    {
        self::assertSame('I', mb_strtoupper('i', 'UTF-8'));
        self::assertSame('İY', DisplayName::fromInput('irem yılmaz')->initials());
    }

    public function test_non_latin_names_are_not_special_cased(): void
    {
        self::assertSame('ΓΠ', DisplayName::fromInput('Γιώργος Παπαδόπουλος')->initials());
    }
}
