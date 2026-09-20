<?php

declare(strict_types=1);

namespace App\Rules;

use App\Support\EmailAddress;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The address has to be one this application would actually store.
 *
 * The sibling of `ValidPhoneNumber`, and it exists for the same reason: a bare
 * `email:strict` rule would accept values `App\Support\EmailAddress` then
 * refuses — a carriage return anywhere, or a length its canonical form exceeds
 * — so the request boundary and the normalizer would disagree about what an
 * address is, and the disagreement would surface as a 500 from a path that had
 * already validated.
 *
 * So validation delegates to the same normalizer the rest of the application
 * uses. One definition of "an email address", not two.
 */
final class ValidEmailAddress implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! EmailAddress::isValid($value)) {
            // Deliberately generic. It says the input was not an address,
            // never anything about whether that address is known here.
            $fail('The :attribute field must be a valid email address.');
        }
    }
}
