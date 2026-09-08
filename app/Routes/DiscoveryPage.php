<?php

declare(strict_types=1);

namespace App\Routes;

/**
 * A page of discovery results, and where the next one starts.
 *
 * `routes` is as long as the caller asked for whenever that many eligible
 * routes exist. Candidates the domain rejects — a departure already behind us,
 * an owner with no profile — are stepped over during the scan and never occupy
 * a slot, so a short page means there were not enough eligible routes, not that
 * the search happened to land on a bad window.
 *
 * `nextCursor` is present only when another eligible route was actually found
 * beyond this page. **Null means genuinely exhausted**: there is no eligible
 * route left anywhere behind this position, and asking again would return
 * nothing. An empty page therefore always carries a null cursor.
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
