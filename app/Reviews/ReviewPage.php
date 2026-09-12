<?php

declare(strict_types=1);

namespace App\Reviews;

use App\Support\KeysetCursor;

/**
 * One page of released reviews, and where the next one starts.
 *
 * `nextCursor` is null at the end of the list — and only there. An empty page
 * does not mean the end, which is why this is always present rather than
 * omitted when there is nothing after.
 *
 * There is no count. A total would be an aggregate, and Phase 15 publishes
 * none — not even to the member the reviews are about, because a number is the
 * first half of a reputation and the second half arrives without anybody
 * deciding to build it.
 */
final readonly class ReviewPage
{
    /**
     * @param  list<ReceivedReview>  $reviews
     */
    public function __construct(
        public array $reviews,
        public ?KeysetCursor $nextCursor,
    ) {}
}
