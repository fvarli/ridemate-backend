<?php

declare(strict_types=1);

namespace App\Routes;

use App\Models\Profile;
use App\Models\Route;

/**
 * One published journey, and the member who published it.
 *
 * The two are carried together because discovery's whole point is that a route
 * has an owner a stranger can recognise. Pairing them here rather than at the
 * HTTP layer means the query cannot return a route whose driver was not
 * resolved — the type will not let it.
 *
 * The DRIVER IS A PROFILE, NOT AN ACCOUNT. An account is a credential; a
 * profile is what another member may see. Discovery never holds the account,
 * so there is nothing for a careless projection to leak.
 *
 * Projection is still the HTTP layer's job. This is the domain's answer, and it
 * carries whole models on purpose — the same way ListMyRoutes hands Route
 * models to RoutePayload — so that exactly one place decides which fields go on
 * the wire.
 */
final readonly class DiscoveredRoute
{
    public function __construct(
        public Route $route,
        public Profile $driver,
    ) {}
}
