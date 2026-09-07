<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Profiles\DisplayName;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a member may say about themselves, and nothing else.
 *
 * ONE FIELD, AND UNKNOWN KEYS REFUSED
 *
 * The contract says `additionalProperties: false`, and Laravel does not: a
 * FormRequest quietly drops what it was not asked about, so a client sending
 * `account_id`, `initials`, `avatar_url` or `trust_score` would get 200 and
 * never learn the field did nothing. Refusing is the honest answer and the one
 * the document already promises. Same idiom as PublishRouteRequest, an allowlist
 * rather than a denylist, because a denylist passes the next invented field.
 *
 * `initials` is refused like any other unknown key. It is derived from the name
 * on the server, and accepting it would let a member's initials disagree with
 * the name printed beside them.
 *
 * `account_id` is refused for the reason it always is: ownership comes from the
 * credential, and a request that could name an owner could rename somebody else.
 *
 * WHY THE LENGTH RULE IS DUPLICATED HERE
 *
 * App\Profiles\DisplayName is the authority and rejects the same inputs by
 * throwing. Validating first turns that into a field-level 422 the client can
 * put next to the control that produced it, rather than an exception surfacing
 * as a 500. The domain check remains, so a caller that reaches it another way
 * is still refused — the same arrangement Phase 10 uses for departures.
 */
final class SaveProfileRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED = ['display_name'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => [
                'required',
                'string',
                // Characters, not bytes — `Ayşe` is four of one and five of the
                // other, and Laravel's string sizes are already multibyte-aware.
                'min:1',
                'max:'.DisplayName::MAX_LENGTH,
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->refuseUnknownKeys($validator);
            $this->refuseANameThatIsOnlyWhitespace($validator);
        });
    }

    /** The trimmed, validated name. */
    public function displayName(): DisplayName
    {
        return DisplayName::fromInput($this->string('display_name')->value());
    }

    private function refuseUnknownKeys(Validator $validator): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->all();

        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, self::ALLOWED, true)) {
                // Named explicitly rather than through `:attribute`: messages
                // added straight to the bag skip Laravel's placeholder
                // substitution, so the placeholder would reach the client
                // verbatim.
                $validator->errors()->add((string) $key, sprintf('The %s field is not accepted.', $key));
            }
        }
    }

    /**
     * A field holding only spaces is empty, however many of them there are.
     *
     * `min:1` counts characters and would accept `"   "`, which then trims to
     * nothing and would reach the domain as an exception. Checked here so it
     * arrives as the validation failure it actually is.
     */
    private function refuseANameThatIsOnlyWhitespace(Validator $validator): void
    {
        $name = $this->input('display_name');

        if (! is_string($name) || $validator->errors()->has('display_name')) {
            return;
        }

        if (preg_replace('/^\s+|\s+$/u', '', $name) === '') {
            $validator->errors()->add('display_name', 'The :attribute field is required.');
        }
    }
}
