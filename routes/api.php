<?php

declare(strict_types=1);

/*
 * The versioned product API — /api/v1.
 *
 * Deliberately empty. Phase 8 establishes the namespace, its middleware and
 * its error contract without inventing a resource to demonstrate them; an
 * unknown path under this prefix already returns the standard JSON 404, which
 * is real behaviour rather than manufactured CRUD.
 *
 * The first entries arrive in Phase 9 with authentication.
 */
