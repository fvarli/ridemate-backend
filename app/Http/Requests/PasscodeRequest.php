<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Rules\ValidPhoneNumber;
use App\Support\PhoneNumber;
use Illuminate\Foundation\Http\FormRequest;
use RuntimeException;

/**
 * Shared by both passcode endpoints: the phone rules, and the one place a
 * request turns into a canonical identity.
 *
 * Normalization happens HERE, at the boundary, and exactly once. Everything
 * downstream — policy lookups, challenge rows, account resolution — receives
 * canonical E.164 and may assume it. That assumption is what makes
 * `unique(phone_e164)` an identity constraint rather than a constraint on
 * spelling, and it only holds if no path reaches the domain without passing
 * through a request like this one.
 */
abstract class PasscodeRequest extends FormRequest
{
    /**
     * The canonical identity for this request.
     *
     * Safe to call only after validation, which is the only time it is called:
     * the rule above has already established that the value normalizes.
     */
    public function phoneE164(): string
    {
        $phone = $this->input('phone');

        $normalized = is_string($phone) ? PhoneNumber::normalize($phone) : null;

        // Not a user-facing path. Reaching it would mean validation and
        // normalization disagreed, which is a bug rather than bad input.
        return $normalized ?? throw new RuntimeException(
            'A validated phone number failed to normalize.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function phoneRules(): array
    {
        return ['phone' => ['required', 'string', 'min:4', 'max:32', new ValidPhoneNumber]];
    }
}
