<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Whether an account may authenticate.
 *
 * Two cases, because two cases have behaviour today. Every state RideMate will
 * eventually want — deactivated, deleted, pending — is deliberately absent
 * until something both produces and consumes it. A status the application can
 * never enter is not a lifecycle; it is a comment that the database enforces.
 *
 * This is NOT verification state and NOT onboarding state. Those three have
 * been separate concepts across the whole product and nothing may couple them.
 */
enum AccountStatus: string
{
    /** Normal. Signs in, refreshes, and makes authenticated requests. */
    case Active = 'active';

    /**
     * Refused everywhere: at passcode verification, at refresh, and at every
     * authenticated request.
     *
     * Refusal is `forbidden`, never `unauthenticated` — the credential is
     * genuine and the account is not permitted, and the client has to tell a
     * member "your account is suspended" rather than sending them to sign in
     * again on a loop.
     */
    case Suspended = 'suspended';
}
