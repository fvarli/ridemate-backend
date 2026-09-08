<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Routes\RouteCursor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * What a member may ask discovery, and nothing else.
 *
 * TWO PLACES, AND A POSITION IN THE RESULT
 *
 * Endpoints are chosen by their server-owned ids, the same way publication
 * chooses them. No coordinates, no radius, no distance: the client has no
 * geography and the query performs none.
 *
 * WHAT IS DELIBERATELY NOT HERE
 *
 * The Search screen collects seats, filters and a sort order. None of them are
 * accepted, because none of them is a thing this query can honestly do. Seats
 * would imply availability, which nothing tracks until seat requests exist. A
 * sort would imply an ordering worth choosing between, and the only ordering
 * that is true is the one the list already has. A date would imply matching a
 * journey to a day, which needs occurrences nobody has built.
 *
 * They stay UI state until something can answer them. Accepting a parameter and
 * ignoring it is how a client comes to believe a filter works.
 *
 * UNKNOWN KEYS ARE REFUSED
 *
 * Laravel ignores what it was not asked about, so `?radius=2000` would return
 * 200 and a list that had nothing to do with the radius. Refusing is the honest
 * answer, and it is the same allowlist idiom PublishRouteRequest uses on a body.
 */
final class DiscoverRoutesRequest extends FormRequest
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /** @var list<string> */
    private const ALLOWED = ['origin_place_id', 'destination_place_id', 'cursor', 'limit'];

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            // `exists` here rather than a 404 later: an id that is not in the
            // catalogue is a malformed request, not a missing resource, and it
            // is the same rule publication applies to the same two fields.
            'origin_place_id' => ['required', 'string', 'uuid', 'exists:places,id'],
            'destination_place_id' => [
                'required',
                'string',
                'uuid',
                'exists:places,id',
                // Searching a journey from a place to itself is not a journey.
                // Publication refuses it for the same reason.
                'different:origin_place_id',
            ],
            'cursor' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->refuseUnknownKeys($validator);
            $this->refuseAnUnusableCursor($validator);
        });
    }

    public function cursor(): ?RouteCursor
    {
        $cursor = $this->input('cursor');

        return is_string($cursor)
            ? RouteCursor::decode($cursor, RouteCursor::DISCOVERY)
            : null;
    }

    public function limit(): int
    {
        $limit = $this->input('limit');

        return is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;
    }

    public function originPlaceId(): string
    {
        return $this->string('origin_place_id')->value();
    }

    public function destinationPlaceId(): string
    {
        return $this->string('destination_place_id')->value();
    }

    private function refuseUnknownKeys(Validator $validator): void
    {
        /** @var array<string, mixed> $query */
        $query = $this->query();

        foreach (array_keys($query) as $key) {
            if (! in_array((string) $key, self::ALLOWED, true)) {
                $validator->errors()->add(
                    (string) $key,
                    sprintf('The %s parameter is not accepted.', $key),
                );
            }
        }
    }

    /**
     * A bad cursor is a bad REQUEST, not a server fault.
     *
     * Decoded here so a tampered, truncated or foreign cursor becomes a 422
     * naming the field rather than an exception from the query builder. A My
     * Routes cursor is foreign: the two lists order the same tuple over
     * completely different rows, so resuming one from the other would land at a
     * position that is arithmetically valid and means nothing.
     */
    private function refuseAnUnusableCursor(Validator $validator): void
    {
        $cursor = $this->input('cursor');

        if ($cursor === null) {
            return;
        }

        if (! is_string($cursor) || RouteCursor::decode($cursor, RouteCursor::DISCOVERY) === null) {
            // Says it is unusable and nothing about why. Which failure mode it
            // hit is not the client's business, and describing them would
            // describe the format.
            $validator->errors()->add('cursor', 'The cursor is not valid.');
        }
    }
}
