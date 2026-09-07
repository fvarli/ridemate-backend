<?php

declare(strict_types=1);

namespace App\Profiles;

use App\Models\Profile;

/**
 * What a save did, alongside what it produced.
 *
 * The endpoint answers 201 for a create and 200 for an update, and only the
 * action knows which happened — a controller re-checking afterwards would be
 * asking a question whose answer had already changed. Two fields rather than a
 * bare model, because "did this exist a moment ago" is not recoverable from the
 * row.
 */
final readonly class SavedProfile
{
    public function __construct(
        public Profile $profile,
        public bool $created,
    ) {}
}
