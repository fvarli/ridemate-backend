<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * `POST /api/v1/registrations/complete`.
 *
 * The credential, and the device the session about to be opened belongs to.
 * Nothing else: the identifiers were bound and proven in earlier steps, and
 * accepting either here would let a caller name an address at the one moment
 * the application stops asking for proof.
 *
 * **No profile data either.** A display name is `PUT /api/v1/me/profile`, which
 * is authenticated by the token pair this endpoint returns. Folding it in would
 * make the one endpoint that creates an account also the one that creates a
 * public identity, and a failure in the second would have to unwind the first.
 *
 * The device fields are `DescribesDevice`'s, shared with
 * `POST /api/v1/auth/otp/verify` — both open a session, so both describe one
 * the same way.
 */
final class CompleteRegistrationRequest extends RegistrationRequest
{
    use DescribesDevice;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->credentialRules() + $this->deviceRules();
    }
}
