<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\Profile;

/**
 * A member's public identity, projected onto the two fields the contract has.
 *
 * WHY SO LITTLE
 *
 * Everything omitted here is omitted because nothing needs it, not for brevity.
 * `id` and `account_id` are internal: Phase 11 publishes no public profile id,
 * and Phase 12's driver projection needs a name and initials, not a handle.
 * `created_at` and `updated_at` are infrastructure timestamps that say when a
 * row was touched, which is a fact about the database rather than about the
 * member. Publishing any of them would mean supporting them afterwards.
 *
 * The fields are named rather than serialized, exactly as AccountPayload does
 * and for the same reason: `$profile->toArray()` would emit whatever a future
 * migration adds, silently, in a response that had already gone out.
 *
 * INITIALS ARE ASKED FOR, NOT COMPUTED HERE
 *
 * `$profile->initials()` reaches App\Profiles\DisplayName, which is the one
 * place the rule lives. Deriving them here would be a second implementation of
 * a deterministic algorithm, and the two would eventually disagree about a
 * member's name — with only one of them getting fixed.
 */
final class ProfilePayload
{
    /**
     * @return array{profile: array{display_name: string, initials: string}}
     */
    public static function from(Profile $profile): array
    {
        return [
            'profile' => [
                'display_name' => $profile->display_name,
                'initials' => $profile->initials(),
            ],
        ];
    }
}
