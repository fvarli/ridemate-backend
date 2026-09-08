<?php

declare(strict_types=1);

namespace App\Routes;

/**
 * A page of discovery results, and where the next one starts.
 *
 * `nextCursor` is null when there is nothing after this page — and only then.
 * An empty `routes` list does not mean the end: the domain filters a page after
 * the database has chosen it, so a page can be short, or even empty, while more
 * rows remain. Callers must read the cursor, never the count.
 */
final readonly class DiscoveryPage
{
    /**
     * @param  list<DiscoveredRoute>  $routes
     */
    public function __construct(
        public array $routes,
        public ?RouteCursor $nextCursor,
    ) {}
}
