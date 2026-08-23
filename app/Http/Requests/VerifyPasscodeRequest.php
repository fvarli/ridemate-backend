<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Auth\DeviceDescription;

/**
 * `POST /api/v1/auth/otp/verify`.
 *
 * The device fields are validated for SHAPE only — a length cap so nobody
 * stores a novel in a session row. They are never trusted as identity, never
 * consulted when deciding whether to authenticate, and never authorised on. A
 * client can claim any device name it likes; the only thing that proves
 * anything here is the passcode.
 */
final class VerifyPasscodeRequest extends PasscodeRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->phoneRules() + [
            // Digits only, and exactly as many as the contract publishes.
            // Anything else is refused before it can cost an attempt.
            'code' => ['required', 'string', 'regex:/^[0-9]{6}$/'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:32'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    public function passcode(): string
    {
        return (string) $this->input('code');
    }

    public function device(): DeviceDescription
    {
        return new DeviceDescription(
            name: $this->stringOrNull('device_name'),
            platform: $this->stringOrNull('platform'),
            appVersion: $this->stringOrNull('app_version'),
        );
    }

    private function stringOrNull(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
