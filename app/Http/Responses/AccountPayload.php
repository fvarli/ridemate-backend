<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\Account;

/**
 * The account, projected onto the five fields the contract publishes.
 *
 * WHY THIS NAMES EVERY FIELD INSTEAD OF SERIALIZING THE MODEL
 *
 * `$account->toArray()` would work today and would be wrong tomorrow. It emits
 * `updated_at`, which the contract does not include, and it will emit whatever
 * a future migration adds — silently, on the day that migration lands, in a
 * response that already went out to clients.
 *
 * That failure is invisible to any test that checks the fields it expects to
 * find, because the extra one is not among them. The contract's
 * additionalProperties: false does catch it at the operation level, and it
 * catches it AFTER someone has already written the column into a response.
 * Naming the fields means the leak cannot be introduced at all: a new column
 * appears here only when someone decides it should.
 *
 * WHAT IS DELIBERATELY ABSENT
 *
 * `updated_at` — an infrastructure timestamp, not a fact about the member.
 * Session, token, generation and device fields — the server's bookkeeping, and
 * a map of the credential chain if published. Profile, display name, email,
 * verification state, Trust Score, roles — none of which exist, and a contract
 * describing them would be describing an intention.
 *
 * `phone_e164` is here because this is the one response where it belongs: the
 * owner's own account. It never appears in any payload another member can see.
 */
final class AccountPayload
{
    /**
     * @return array{account: array{id: string, phone_e164: string, phone_verified_at: string, status: string, created_at: string}}
     */
    public static function from(Account $account): array
    {
        return [
            'account' => [
                'id' => $account->id,
                'phone_e164' => $account->phone_e164,
                // Atom is RFC 3339 with an explicit offset, which is what the
                // contract's date-time format means. Laravel's default JSON
                // datetime carries six fractional digits and reads as noise.
                'phone_verified_at' => $account->phone_verified_at->toAtomString(),
                'status' => $account->status->value,
                'created_at' => $account->created_at->toAtomString(),
            ],
        ];
    }
}
