<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared by every request that continues a registration: the credential field,
 * and nothing else.
 *
 * WHY THE CREDENTIAL TRAVELS IN THE BODY
 *
 * The same argument `RefreshTokenRequest` makes, and it is stronger here. A
 * registration credential authorises exactly one thing — advancing the
 * registration it names — and it is not a bearer credential for this API:
 * `AuthenticateToken` resolves `rma_` against `auth_tokens`, so an `rmreg_`
 * value cannot even parse there. Accepting it in an `Authorization` header
 * would teach clients to attach it to ordinary requests, where it would spread
 * through logs and proxies, and would model as a session something that is
 * explicitly not one.
 *
 * There is no cookie either, and no registration id in any path. The row id is
 * inside the credential, which is what makes resolution a primary-key read; a
 * path segment carrying it would look like an address and be treated as an
 * authorization by the first client that tried it.
 *
 * ONLY PRESENCE, TYPE AND A LENGTH CAP ARE CHECKED
 *
 * Deliberately not the shape. `RegistrationSecret::parse()` refuses a malformed
 * value before any query, and `RegistrationService::resolve()` answers the same
 * null for malformed, unknown, wrong-secret, expired and completed alike — one
 * `401` for all five. A regex here would split the first of those off into a
 * `422`, which is a difference a caller could measure, and the whole point of
 * the collapse is that there is nothing to measure.
 *
 * The cap is the same 512 the refresh credential carries: enough for any value
 * this service mints, and a bound on what reaches the parser.
 */
abstract class RegistrationRequest extends FormRequest
{
    public function credential(): string
    {
        return (string) $this->input('registration_credential');
    }

    /**
     * @return array<string, mixed>
     */
    protected function credentialRules(): array
    {
        return ['registration_credential' => ['required', 'string', 'max:512']];
    }
}
