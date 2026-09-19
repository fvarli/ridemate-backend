<?php

declare(strict_types=1);

namespace App\Registration;

/**
 * Why a registration could not be completed into an account.
 *
 * NOT A WIRE VOCABULARY, AND DELIBERATELY NOT ONE YET
 *
 * `App\Trips\RefusalReason` and its siblings publish their strings as
 * `details.reason`, because a controller serializes them. Nothing serializes
 * these: there is no public completion endpoint, and what a client is allowed
 * to be told is the next slice's decision rather than a byproduct of this one.
 *
 * That decision is not cosmetic. `EmailAlreadyRegistered` and
 * `PhoneAlreadyRegistered` each answer "does an account already exist for this
 * identifier?" — which is exactly the question an enumeration attempt asks, and
 * which the sign-in path has never been willing to answer. The domain needs the
 * distinction to be truthful internally; publishing it is a separate argument,
 * and it has not been made.
 *
 * The strings are snake_case anyway, in the repository's one style, so that the
 * day one of them IS published nobody has to rename a case and re-argue it.
 */
enum RefusalReason: string
{
    /**
     * Expired, or already completed. One case, for the reason
     * `Registration::isAdvanceable()` collapses them: telling the two apart
     * would tell the holder of a credential whether a registration it does not
     * own was ever finished.
     *
     * It also covers a registration that no longer exists at all, which is the
     * same ending from outside.
     */
    case RegistrationEnded = 'registration_ended';

    /**
     * One of the two possessions has not been proven for THIS registration.
     *
     * Covers a missing proof and an unbound identifier alike. They are not two
     * states worth separating here: the `registrations` CHECK constraints make
     * a proof without its identifier unrepresentable, so "unproven" and
     * "unbound or unproven" name the same set of rows.
     */
    case NotFullyProven = 'not_fully_proven';

    /** An account already holds this canonical address. */
    case EmailAlreadyRegistered = 'email_already_registered';

    /** An account already holds this canonical number. */
    case PhoneAlreadyRegistered = 'phone_already_registered';
}
