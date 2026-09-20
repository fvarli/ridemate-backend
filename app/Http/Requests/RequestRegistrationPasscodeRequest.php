<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Otp\OtpChannel;
use App\Rules\ValidEmailAddress;
use App\Rules\ValidPhoneNumber;
use Illuminate\Validation\Rule;

/**
 * `POST /api/v1/registrations/otp`.
 *
 * A credential, a channel, and the destination that channel's passcode goes to.
 *
 * WHY THE DESTINATION IS SUPPLIED HERE AND NOWHERE ELSE
 *
 * Because this is the only step at which a registration may come to name one.
 * `RegistrationService::bind()` is called by `SendRegistrationPasscode` and by
 * nothing else, so a destination is named at the moment a code is sent to it —
 * which is what stops a registration naming an address it never asked for a
 * code at. Verification takes no destination at all, and that asymmetry is the
 * security property the whole pre-account boundary rests on.
 *
 * WHY A CHANNEL AND A DESTINATION RATHER THAN AN `email` AND A `phone` FIELD
 *
 * The registration starts empty and the two channels may be proven in either
 * order, so a request that carried both would be describing a state machine
 * this one does not have. One channel per request is what the domain takes —
 * `SendRegistrationPasscode(registration, channel, destination)` — and the pair
 * of fields says exactly that, without an exclusive-or rule standing in for a
 * discriminator that already exists.
 *
 * VALIDATION IS THE NORMALIZER'S, DISPATCHED ON THE CHANNEL
 *
 * `ValidPhoneNumber` and `ValidEmailAddress` both delegate to the value object
 * the destination will actually be canonicalized through, so the boundary and
 * `bind()` cannot disagree about what a destination is. A disagreement would
 * surface as a `500` from a request that had already validated.
 *
 * An unknown channel fails first, so the destination rule is never chosen from
 * a value the contract does not publish.
 */
final class RequestRegistrationPasscodeRequest extends RegistrationRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return $this->credentialRules() + [
            'channel' => ['required', 'string', Rule::in(array_column(OtpChannel::cases(), 'value'))],
            'destination' => $this->destinationRules(),
        ];
    }

    public function channel(): OtpChannel
    {
        return OtpChannel::from((string) $this->input('channel'));
    }

    /**
     * As the member typed it. Canonicalization is `RegistrationService::bind()`'s,
     * through the value objects `registrations` and `otp_challenges` are keyed
     * on — a second opinion here would be a second identity.
     */
    public function destination(): string
    {
        return (string) $this->input('destination');
    }

    /**
     * @return array<int, mixed>
     */
    private function destinationRules(): array
    {
        $channel = $this->input('channel');

        return match ($channel) {
            OtpChannel::Sms->value => ['required', 'string', 'min:4', 'max:32', new ValidPhoneNumber],
            OtpChannel::Email->value => ['required', 'string', 'min:3', 'max:254', new ValidEmailAddress],
            // The channel rule above has already failed; this keeps the field
            // required so a request missing both is told about both.
            default => ['required', 'string'],
        };
    }
}
