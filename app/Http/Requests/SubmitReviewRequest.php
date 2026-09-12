<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Rating a relationship: one client-generated id and one number.
 *
 * The relationship is in the path, the reviewer is the authenticated caller,
 * and **which side they are is not theirs to say** — the server derives it from
 * the seat request, so a passenger cannot file a review as the driver. Anything
 * else in the body is refused rather than ignored: a key the server silently
 * drops is a feature the client believes it is using.
 *
 * There is no text field. Phase 15 is rating-only, because free text with no
 * reporting, moderation or deletion is an unmoderated channel between two
 * people who know each other's names and commute.
 */
final class SubmitReviewRequest extends FormRequest
{
    /** @var list<string> */
    private const ALLOWED = ['id', 'rating'];

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            // UUIDv7 specifically, for the reason asking for a seat gives: the
            // id doubles as the idempotency key and as the row's primary key.
            'id' => ['required', 'string', 'uuid:7'],
            // Whole, one to five. Not a decimal — a fractional rating on a
            // single review is an invitation for some client to average them,
            // and Phase 15 publishes no aggregate at all.
            'rating' => ['required', 'integer', 'between:1,5'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var array<string, mixed> $body */
            $body = $this->all();

            foreach (array_keys($body) as $key) {
                if (! in_array($key, self::ALLOWED, true)) {
                    $validator->errors()->add(
                        $key,
                        sprintf('The %s field is not accepted.', $key),
                    );
                }
            }
        });
    }

    public function reviewId(): string
    {
        return $this->string('id')->value();
    }

    public function rating(): int
    {
        return $this->integer('rating');
    }
}
