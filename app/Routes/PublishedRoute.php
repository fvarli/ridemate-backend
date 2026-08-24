<?php

declare(strict_types=1);

namespace App\Routes;

use App\Models\Route;

/**
 * The outcome of publishing, and whether anything happened.
 *
 * The flag exists so the HTTP layer can answer 201 for a journey that was just
 * created and 200 for one a retry found already there, without re-deriving that
 * distinction from timestamps. The action knows; nothing downstream should have
 * to guess.
 */
final readonly class PublishedRoute
{
    public function __construct(
        public Route $route,
        public bool $wasAlreadyPublished,
    ) {}
}
