<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Reviews\ListMyReviews;

/** A member paging what others have said about them. */
final class ListMyReviewsRequest extends PagedFeedRequest
{
    protected function surface(): string
    {
        return ListMyReviews::CURSOR;
    }
}
