<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\PhoneNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * One human, one identity, however they type it.
 *
 * This is the test that justifies a dependency. Every case below is a real way
 * an Istanbul member writes their own number, and each one has to arrive at the
 * same row — because `unique(phone_e164)` only prevents duplicate ACCOUNTS if
 * it is applied to a canonical value. Applied to raw input it prevents nothing
 * at all: four spellings, four accounts, four trust histories.
 */
final class PhoneNumberTest extends TestCase
{
    private const CANONICAL = '+905321234567';

    /**
     * @return array<string, array{string}>
     */
    public static function turkishRepresentations(): array
    {
        return [
            'national with trunk zero' => ['05321234567'],
            'national, spaced as printed' => ['0532 123 45 67'],
            'international with plus' => ['+905321234567'],
            'international with plus, spaced' => ['+90 532 123 45 67'],
            'country code, no plus' => ['905321234567'],
            'country code, no plus, spaced' => ['90 532 123 45 67'],
            'international prefix 00' => ['00905321234567'],
            'international prefix 00, spaced' => ['0090 532 123 45 67'],
            'parentheses and hyphens' => ['(0532) 123-45-67'],
            'surrounding whitespace' => [' +90-532-123-45-67 '],
            'no trunk zero at all' => ['532 123 45 67'],
        ];
    }

    #[DataProvider('turkishRepresentations')]
    public function test_every_turkish_representation_yields_one_identity(string $input): void
    {
        self::assertSame(self::CANONICAL, PhoneNumber::normalize($input));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidInputs(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
            'far too short' => ['12'],
            'letters' => ['abcdefghij'],
            'far too long' => ['0532123456789012'],
            'no country code exists for this' => ['++90532'],
            'all zeroes' => ['0000000000'],

            // The case a regular expression waves through. This has a valid
            // Turkish country code and the right number of digits, and no
            // carrier owns the 999 range. Only per-country metadata knows that.
            'well-formed but not a real range' => ['+90 999 999 99 99'],
        ];
    }

    #[DataProvider('invalidInputs')]
    public function test_invalid_input_is_rejected(string $input): void
    {
        self::assertNull(PhoneNumber::normalize($input));
        self::assertFalse(PhoneNumber::isValid($input));
    }

    /**
     * The pilot is Istanbul; the product is not restricted to Türkiye.
     *
     * A tester with a foreign SIM is someone RideMate wants, and a
     * "+90 only" rule would turn them away at the first screen.
     */
    public function test_full_international_numbers_are_honoured(): void
    {
        self::assertSame('+442071838750', PhoneNumber::normalize('+44 20 7183 8750'));
        self::assertSame('+4930901820', PhoneNumber::normalize('+49 30 901820'));
    }

    /**
     * The region is a PARSING default for numbers typed without a country
     * code, resolved from configuration rather than baked into the class.
     *
     * Proven by changing it: the same bare national number reads as a
     * different country's.
     */
    public function test_the_default_region_only_applies_to_numbers_without_a_country_code(): void
    {
        config(['ridemate.phone.default_region' => 'GB']);

        self::assertSame('+442071838750', PhoneNumber::normalize('020 7183 8750'));

        // A number that already carries its country code is unaffected by it.
        self::assertSame(self::CANONICAL, PhoneNumber::normalize('+90 532 123 45 67'));
    }

    /** Normalizing an already-canonical value must not change it. */
    public function test_normalization_is_idempotent(): void
    {
        $once = PhoneNumber::normalize('0532 123 45 67');
        self::assertNotNull($once);
        self::assertSame($once, PhoneNumber::normalize($once));
    }
}
