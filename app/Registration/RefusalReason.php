<?php

declare(strict_types=1);

namespace App\Registration;

/**
 * Why a registration could not be advanced, or could not be completed into an
 * account.
 *
 * AN INTERNAL VOCABULARY THAT IS NOT THE WIRE VOCABULARY
 *
 * Every other `RefusalReason` in this application — seat requests, trips,
 * reviews — is published verbatim as `details.reason`, one case to one wire
 * string. This one is not, and the difference is the point rather than an
 * inconsistency.
 *
 * `EmailAlreadyRegistered` and `PhoneAlreadyRegistered` each answer "does an
 * account already exist for THIS identifier?" — which is exactly the question
 * an enumeration attempt asks, and which the sign-in path has never been
 * willing to answer. The domain needs the distinction to be truthful
 * internally: it is what a log line or an operator query would have to say. The
 * public surface collapses the two into one `account_already_exists`, because a
 * caller that could tell them apart could aim a registration at an identifier
 * pair and read back which half was taken.
 *
 * `RegistrationEnded` is not published at all. It is the same answer
 * `RegistrationService::resolve()` already gives for a malformed, unknown or
 * wrong-secret credential — 401, with nothing in `details` — so a holder of a
 * credential learns that it stopped working and never whether the registration
 * behind it expired or was finished.
 *
 * The mapping lives in `App\Support\ExceptionRenderer`, in one place, so that
 * publishing a case is an edit to a table somebody reviews rather than a
 * byproduct of adding one here.
 *
 * The strings are snake_case in the repository's one style, so that the day one
 * of them IS published nobody has to rename a case and re-argue it.
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

    /**
     * The registration already names a DIFFERENT destination on this channel.
     *
     * Binding is write-once per channel, so a second destination is refused
     * rather than adopted — see `RegistrationService::bind()`. This is the one
     * case here that is a fact about the caller's OWN registration and about
     * nothing else: it names neither destination, says nothing about whether
     * the bound one was proven, and could not be reached without holding the
     * credential. It is published as it stands.
     */
    case ChannelAlreadyBound = 'channel_already_bound';
}
