<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Journeys\JourneyCursor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The two query parameters a driver may use to page their own journeys.
 *
 * A bad cursor is a bad REQUEST, not a server fault. Decoding happens here so
 * that a tampered, truncated or wrong-surface cursor becomes a 422 naming the
 * field, rather than an exception surfacing from the query builder as a 500.
 */
final class ListMyJourneysRequest extends FormRequest
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $cursor = $this->input('cursor');

            if ($cursor === null) {
                return;
            }

            if (! is_string($cursor) || JourneyCursor::decode($cursor) === null) {
                // Says the cursor is not usable and nothing about why. Which
                // failure mode it hit is not the client's business, and
                // describing them would describe the format.
                $validator->errors()->add('cursor', 'The cursor is not valid.');
            }
        });
    }

    public function cursor(): ?JourneyCursor
    {
        $cursor = $this->input('cursor');

        return is_string($cursor) ? JourneyCursor::decode($cursor) : null;
    }

    public function limit(): int
    {
        $limit = $this->input('limit');

        return is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;
    }
}
