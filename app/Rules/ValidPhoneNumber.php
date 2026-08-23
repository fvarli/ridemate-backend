<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\PhoneNumber;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The number has to be a real one, not merely a plausible string.
 *
 * A length-and-digits rule would accept `+90 999 999 99 99` — right country
 * code, right digit count, a range no carrier owns. Only per-country metadata
 * knows the difference, so validation delegates to the same normalizer the rest
 * of the application uses. One definition of "a phone number", not two.
 */
final class ValidPhoneNumber implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! PhoneNumber::isValid($value)) {
            // Deliberately generic. It says the input was not a phone number,
            // never anything about whether that number is known here.
            $fail('The :attribute field must be a valid phone number.');
        }
    }
}
