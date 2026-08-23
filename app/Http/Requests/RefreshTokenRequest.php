<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/v1/auth/refresh`.
 *
 * The credential travels in the BODY, and the route carries no authentication
 * middleware. That is a deliberate contract decision rather than an oversight:
 * a refresh token authorises exactly one action, and accepting it in an
 * Authorization header would teach clients to attach it to ordinary requests,
 * where it would spread through logs and proxies that were only ever meant to
 * see a short-lived token.
 *
 * Only presence and type are checked here. Shape, ownership and validity are
 * the token service's business, and every failure it can produce is the same
 * public outcome.
 */
final class RefreshTokenRequest extends FormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['refresh_token' => ['required', 'string', 'max:512']];
    }

    public function refreshToken(): string
    {
        return (string) $this->input('refresh_token');
    }
}
