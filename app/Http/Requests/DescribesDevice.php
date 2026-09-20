<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Auth\DeviceDescription;

/**
 * The three optional fields a client may send about the device a session is
 * being opened on, and the one place they turn into a `DeviceDescription`.
 *
 * Two endpoints open a session — `POST /api/v1/auth/otp/verify` and
 * `POST /api/v1/registrations/complete` — and the contract says they accept the
 * SAME device fields. Writing the rules twice is how that stops being true: one
 * of them gains a field, or a cap drifts, and the difference is a contract
 * change nobody reviewed. It is the argument `TokenPairResponse` makes about
 * the body those endpoints return, applied to the body they take.
 *
 * VALIDATED FOR SHAPE ONLY
 *
 * A length cap, so nobody stores a novel in a session row. These values are
 * never trusted as identity, never consulted when deciding whether to
 * authenticate, and never authorised on. A client can claim any device name it
 * likes; what proves something is the passcode, or the two proofs behind a
 * registration.
 */
trait DescribesDevice
{
    /**
     * @return array<string, mixed>
     */
    protected function deviceRules(): array
    {
        return [
            'device_name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'platform' => ['sometimes', 'nullable', 'string', 'max:32'],
            'app_version' => ['sometimes', 'nullable', 'string', 'max:32'],
        ];
    }

    public function device(): DeviceDescription
    {
        return new DeviceDescription(
            name: $this->deviceStringOrNull('device_name'),
            platform: $this->deviceStringOrNull('platform'),
            appVersion: $this->deviceStringOrNull('app_version'),
        );
    }

    private function deviceStringOrNull(string $key): ?string
    {
        $value = $this->input($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
