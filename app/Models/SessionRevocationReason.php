<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Why a session stopped being valid.
 *
 * Recorded because "why was I signed out?" is a question with four very
 * different answers, and only one of them is routine. Without this column the
 * difference between a member tapping sign-out and a stolen refresh token
 * being caught is invisible.
 */
enum SessionRevocationReason: string
{
    /** The member signed out on this device. */
    case Logout = 'logout';

    /**
     * An already-rotated refresh generation was presented again.
     *
     * Either the credential was stolen, or a client retried a refresh whose
     * response it never received. The server cannot tell those apart, and
     * Phase 9 resolves the ambiguity in favour of security. See TokenService.
     */
    case ReuseDetected = 'reuse_detected';

    /** The account was suspended, so its live sessions went with it. */
    case AccountSuspended = 'account_suspended';

    /** An operator revoked it deliberately, through the console command. */
    case Operator = 'operator';
}
