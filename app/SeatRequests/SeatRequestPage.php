<?php

declare(strict_types=1);

namespace App\SeatRequests;

use App\Support\KeysetCursor;

/**
 * One page of askings, and where the next one starts.
 *
 * `nextCursor` is null at the end of the list — and only there. An empty page
 * does not mean the end, which is why this is always present rather than
 * omitted when there is nothing after.
 *
 * The parameter is covariant because this container is read-only: `T` appears
 * only as something the page hands out, never as something it accepts, so a
 * page of one kind is safely usable wherever a page of a wider kind is
 * expected.
 *
 * @template-covariant T of OwnSeatRequest|IncomingSeatRequest
 */
final readonly class SeatRequestPage
{
    /**
     * @param  list<T>  $requests
     */
    public function __construct(
        public array $requests,
        public ?KeysetCursor $nextCursor,
    ) {}
}
