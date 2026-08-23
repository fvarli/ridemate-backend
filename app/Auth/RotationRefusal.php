<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Why a refresh was refused — returned rather than thrown.
 *
 * THIS IS NOT STYLISTIC.
 *
 * Detecting reuse REVOKES the session, and that revocation is the entire point
 * of the detector. Throwing from inside the transaction that performed it
 * would roll it straight back, leaving a token family that has been recognised
 * as compromised and not actually revoked — a security hole that every test
 * asserting "reuse throws" would still pass, because the exception is real and
 * only the write is missing.
 *
 * So the transaction returns an outcome, commits, and the caller converts it
 * into an exception afterwards.
 */
enum RotationRefusal
{
    /**
     * Malformed, unknown, mismatched, expired, revoked, or reused. Deliberately
     * one case: a caller learns nothing about which.
     */
    case Invalid;

    /** The account exists and is suspended. Distinct because it is a 403. */
    case Suspended;
}
