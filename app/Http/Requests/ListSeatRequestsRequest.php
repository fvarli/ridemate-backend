<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Support\KeysetCursor;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Paging a seat-request feed.
 *
 * Shared by both listings because the query contract is identical; what
 * differs is which surface's cursors are accepted, and that is the one thing
 * each subclass says.
 */
abstract class ListSeatRequestsRequest extends FormRequest
{
    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    /** The cursor version this feed issues, and the only one it accepts. */
    abstract protected function surface(): string;

    /**
     * @return array<string, list<string>>
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

            if (! is_string($cursor) || $this->cursor() === null) {
                // Says the cursor is not usable and nothing about why —
                // tampered, expired format, or issued by another feed. Which
                // one it hit is not the client's business, and describing them
                // would describe the format.
                $validator->errors()->add('cursor', 'The cursor is not valid.');
            }
        });
    }

    public function cursor(): ?KeysetCursor
    {
        $cursor = $this->input('cursor');

        return is_string($cursor)
            ? KeysetCursor::decode($cursor, $this->surface())
            : null;
    }

    public function limit(): int
    {
        $limit = $this->input('limit');

        return is_numeric($limit) ? (int) $limit : self::DEFAULT_LIMIT;
    }
}
