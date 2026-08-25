<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Routes\RouteCursor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The two query parameters a member may use to page their own routes.
 *
 * A bad cursor is a bad REQUEST, not a server fault. Decoding happens here so
 * that a tampered, truncated or stale-format cursor becomes a 422 naming the
 * field, rather than an exception surfacing from the query builder as a 500.
 */
final class ListMyRoutesRequest extends FormRequest
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

            if (! is_string($cursor) || RouteCursor::decode($cursor) === null) {
                // Says the cursor is not usable and nothing about why. Which
                // of the failure modes it hit is not the client's business,
                // and describing them would describe the format.
                $validator->errors()->add('cursor', 'The cursor is not valid.');
            }
        });
    }

    public function cursor(): ?RouteCursor
    {
        $cursor = $this->input('cursor');

        return is_string($cursor) ? RouteCursor::decode($cursor) : null;
    }

    public function limit(): int
    {
        $limit = $this->input('limit');

        return is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;
    }
}
