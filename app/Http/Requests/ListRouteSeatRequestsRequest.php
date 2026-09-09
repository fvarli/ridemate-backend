<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\SeatRequests\ListRouteSeatRequests;

/** A driver paging the askings on one of their journeys. */
final class ListRouteSeatRequestsRequest extends ListSeatRequestsRequest
{
    protected function surface(): string
    {
        return ListRouteSeatRequests::CURSOR;
    }
}
