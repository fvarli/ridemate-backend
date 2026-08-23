<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * `POST /api/v1/auth/otp`.
 *
 * A phone number and nothing else. There is deliberately no field for a
 * preferred channel, a locale or a purpose: each would be another dimension
 * the response could vary along, and this endpoint's contract is that its
 * response never varies at all.
 */
final class RequestPasscodeRequest extends PasscodeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->phoneRules();
    }
}
