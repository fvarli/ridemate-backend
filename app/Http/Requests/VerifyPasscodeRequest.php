<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * `POST /api/v1/auth/otp/verify`.
 *
 * The device fields come from `DescribesDevice`, which is also what
 * `POST /api/v1/registrations/complete` uses: two endpoints open a session and
 * the contract says both take the same three fields, so there is one definition
 * of them. They are validated for SHAPE only and are never trusted as identity,
 * never consulted when deciding whether to authenticate, and never authorised
 * on. A client can claim any device name it likes; the only thing that proves
 * anything here is the passcode.
 */
final class VerifyPasscodeRequest extends PasscodeRequest
{
    use DescribesDevice;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->phoneRules() + [
            // Digits only, and exactly as many as the contract publishes.
            // Anything else is refused before it can cost an attempt.
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ] + $this->deviceRules();
    }

    public function passcode(): string
    {
        return (string) $this->input('code');
    }
}
