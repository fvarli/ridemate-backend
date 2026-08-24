<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteDeparture;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

/**
 * What a driver may say when publishing, and nothing else.
 *
 * WHY UNKNOWN KEYS ARE REFUSED RATHER THAN IGNORED
 *
 * The contract says `additionalProperties: false`, and Laravel does not. A
 * FormRequest silently drops anything it was not asked about, so a client
 * sending `timezone`, `account_id`, `latitude` or a cost field would get 201
 * and quietly have that field discarded — and would keep sending it, believing
 * it worked, until someone eventually noticed the field had never done
 * anything. Refusing is the honest answer, and it is the one the document
 * already promises.
 *
 * A denylist of forbidden names was considered and rejected: it would pass the
 * next field somebody invents, which is precisely the case worth catching. The
 * allowed set is small and known, so the check compares against that instead.
 *
 * WHAT MAY NOT BE SENT AT ALL
 *
 * `account_id` — ownership comes from the credential. A request that could name
 * an owner could publish a journey in somebody else's name.
 *
 * `timezone` — the server's, from pilot configuration. It decides whether a
 * departure has already passed, so a client choosing its own could move its own
 * deadline.
 *
 * Coordinates — the client has none and never needs any. Endpoints are chosen
 * by place id, and the server owns where those places are.
 *
 * Anything to do with money — RideMate charges nobody, and there is no figure
 * here that any driver chose or any policy computed.
 */
final class PublishRouteRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED = [
        'id',
        'origin_place_id',
        'destination_place_id',
        'recurrence',
        'departure_date',
        'departure_time',
        'seats_offered',
        'rules',
    ];

    /** @var list<string> */
    private const ALLOWED_RULES = ['no_smoking', 'music_ok', 'no_pets', 'quiet'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // uuid:7, not uuid. Laravel checks the version field itself, so
            // there is no second definition of "is this a v7" to keep correct.
            'id' => ['required', 'string', 'uuid:7'],

            // Existence is checked here so an unknown place is a field-level
            // 422 rather than a foreign-key error surfacing as a 500.
            'origin_place_id' => ['required', 'string', 'uuid', 'exists:places,id'],
            'destination_place_id' => ['required', 'string', 'uuid', 'exists:places,id', 'different:origin_place_id'],

            'recurrence' => ['required', 'string', 'in:once,weekdays'],

            // date_format is strict by round trip: it parses, formats back, and
            // requires equality, so 2026-02-30 is refused instead of quietly
            // becoming the 2nd of March.
            'departure_date' => ['required_if:recurrence,once', 'prohibited_if:recurrence,weekdays', 'date_format:Y-m-d'],

            // Minutes only. The domain refuses 08:00:00 and so must this.
            'departure_time' => ['required', 'date_format:H:i'],

            // One is the product rule. The ceiling is the column's capacity,
            // stated so an absurd number is a clean 422 instead of a driver
            // watching a 500 — it is not an opinion about how big a car is.
            'seats_offered' => ['required', 'integer', 'min:1', 'max:32767'],

            'rules' => ['required', 'array'],
            'rules.no_smoking' => ['required', 'boolean'],
            'rules.music_ok' => ['required', 'boolean'],
            'rules.no_pets' => ['required', 'boolean'],
            'rules.quiet' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->refuseUnknownKeys($validator);
            $this->refuseADepartureThatHasPassed($validator);
        });
    }

    private function refuseUnknownKeys(Validator $validator): void
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->all();

        foreach (array_keys($payload) as $key) {
            if (! in_array((string) $key, self::ALLOWED, true)) {
                $validator->errors()->add((string) $key, 'The :attribute field is not accepted.');
            }
        }

        $rules = $payload['rules'] ?? null;
        if (! is_array($rules)) {
            return;
        }

        foreach (array_keys($rules) as $key) {
            if (! in_array((string) $key, self::ALLOWED_RULES, true)) {
                $validator->errors()->add("rules.$key", 'The :attribute field is not accepted.');
            }
        }
    }

    /**
     * A journey that has already left cannot be published.
     *
     * Read in the pilot's timezone, which is where the driver is. Checked here
     * as well as in the domain so it arrives as a field error the client can
     * put next to the control that produced it.
     */
    private function refuseADepartureThatHasPassed(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            // The shape is already wrong; a second complaint about the calendar
            // would be noise built on a value we could not parse anyway.
            return;
        }

        try {
            $departure = $this->departure();
        } catch (InvalidArgumentException) {
            return;
        }

        if (! $departure->isUpcoming()) {
            $validator->errors()->add('departure_date', 'The departure has already passed.');
        }
    }

    public function routeId(): string
    {
        return (string) $this->input('id');
    }

    public function originPlaceId(): string
    {
        return (string) $this->input('origin_place_id');
    }

    public function destinationPlaceId(): string
    {
        return (string) $this->input('destination_place_id');
    }

    /**
     * The departure, built by the same value object the domain uses.
     *
     * One definition of "a departure", not two. The timezone is the pilot's and
     * is never read from the request.
     */
    public function departure(): RouteDeparture
    {
        $recurrence = Recurrence::from((string) $this->input('recurrence'));
        $date = $this->input('departure_date');

        /** @var string $timezone */
        $timezone = config('ridemate.pilot.timezone');

        return RouteDeparture::fromInput(
            $recurrence,
            $recurrence->requiresDepartureDate() && is_string($date) ? $date : null,
            (string) $this->input('departure_time'),
            $timezone,
        );
    }

    public function seatsOffered(): int
    {
        return (int) $this->input('seats_offered');
    }

    public function rideRules(): RideRules
    {
        return new RideRules(
            noSmoking: $this->boolean('rules.no_smoking'),
            musicOk: $this->boolean('rules.music_ok'),
            noPets: $this->boolean('rules.no_pets'),
            quiet: $this->boolean('rules.quiet'),
        );
    }
}
