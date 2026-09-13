<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Asking for a seat: one client-generated id, and nothing else.
 *
 * The journey is in the path, the passenger is the authenticated caller, and
 * the status is not the client's to choose. That leaves exactly one field, and
 * anything else in the body is refused rather than ignored — a key the server
 * silently drops is a feature the client believes it is using.
 */
final class RequestSeatRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED = ['id', 'service_date'];

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // UUIDv7 specifically: the id doubles as the idempotency key and
            // as the row's primary key, and a time-ordered one keeps inserts
            // appending to the index rather than scattering across it.
            'id' => ['required', 'string', 'uuid:7'],
            // Optional, and optional on purpose: a one-off journey has a single
            // day the server already knows, so a client that never learned
            // about dates keeps working unchanged. A recurring plan has many,
            // and the command requires one — which it can only do once it knows
            // the recurrence, so the rule is not expressible here.
            'service_date' => ['sometimes', 'string', 'date_format:Y-m-d'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<string, mixed> $body */
            $body = $this->all();

            foreach (array_keys($body) as $key) {
                if (! in_array($key, self::ALLOWED, true)) {
                    // Not `:attribute` — MessageBag::add does not substitute it.
                    $validator->errors()->add(
                        $key,
                        sprintf('The %s field is not accepted.', $key),
                    );
                }
            }
        });
    }

    public function seatRequestId(): string
    {
        return $this->string('id')->value();
    }

    /**
     * The day named, if one was.
     *
     * Parsed with `!` so nothing leaks in from the clock: this is a calendar
     * day, not a moment, and the shape was already proven by the rule above.
     */
    public function serviceDate(): ?CarbonImmutable
    {
        $named = $this->input('service_date');

        if (! is_string($named)) {
            return null;
        }

        $day = CarbonImmutable::createFromFormat('!Y-m-d', $named);
        assert($day instanceof CarbonImmutable);

        return $day;
    }
}
