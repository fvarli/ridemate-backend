<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Otp\OtpChannel;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/registrations/otp/verify`.
 *
 * A credential, a channel, and a code. **There is deliberately nowhere to put a
 * destination**, and that is the whole security property of this endpoint
 * rather than an omission.
 *
 * `VerifyRegistrationPasscode` reads the destination from the locked
 * registration row, which is the same row the proof is written to, so the two
 * can never disagree. The attack the pre-account boundary exists to prevent —
 * verify a code sent to an address you control, attach the proof to a
 * registration naming somebody else's — is not something a check has to catch.
 * It cannot be expressed, and it cannot be expressed here either: a
 * `destination` key in this body would be refused as an unknown field by the
 * contract and read by nothing.
 *
 * The code is digits only and exactly as many as the contract publishes, as it
 * is on the sign-in path, so a malformed one is refused before it can cost an
 * attempt against the challenge.
 */
final class VerifyRegistrationPasscodeRequest extends RegistrationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->credentialRules() + [
            'channel' => ['required', 'string', Rule::in(array_column(OtpChannel::cases(), 'value'))],
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
        ];
    }

    public function channel(): OtpChannel
    {
        return OtpChannel::from((string) $this->input('channel'));
    }

    public function passcode(): string
    {
        return (string) $this->input('code');
    }
}
