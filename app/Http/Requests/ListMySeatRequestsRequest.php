<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\SeatRequests\ListMySeatRequests;

/** A member paging their own askings. */
final class ListMySeatRequestsRequest extends PagedFeedRequest
{
    protected function surface(): string
    {
        return ListMySeatRequests::CURSOR;
    }
}
