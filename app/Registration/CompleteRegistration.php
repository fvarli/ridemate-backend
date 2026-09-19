<?php

declare(strict_types=1);

namespace App\Registration;

use App\Auth\DeviceDescription;
use App\Auth\TokenService;
use App\Models\Account;
use App\Models\Registration;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Turns a registration that has proven both possessions into exactly one
 * account, exactly once.
 *
 * THE INVARIANT, IN ONE SENTENCE
 *
 * A registration either produced an account and is marked completed, or it did
 * neither. There is no ordering of failures that leaves an account whose
 * registration is still open, and none that leaves a completed registration
 * with nothing to show for it — both halves commit together or neither does,
 * which is why this owns the transaction rather than handing pieces of it out.
 *
 * WHAT IS PRESERVED, AND WHY IT IS NOT `now()`
 *
 * The canonical address, the canonical number, and BOTH proof timestamps move
 * across unchanged. `accounts.phone_verified_at` is a domain claim about when
 * possession was demonstrated, and the demonstration happened in
 * `VerifyRegistrationPasscode`, minutes ago. Stamping it with the completion
 * time would make the account assert something false about its own history, and
 * the registration it came from expires — so there would be no second copy to
 * correct it from. Nothing is renormalized on the way, either: the strings were
 * canonicalized once, by `RegistrationService::bind()`, through the same value
 * objects `accounts` is indexed on, and a second opinion here would be a second
 * identity policy.
 *
 * LOCK ORDER: THE REGISTRATION ROW, THEN `accounts`, THEN THE SESSION.
 *
 * The registration is taken `for update` before anything is decided, so two
 * simultaneous completions of one registration serialize rather than both
 * reading `completed_at IS NULL`. Under READ COMMITTED the loser re-reads the
 * row once the lock is granted and sees the winner's committed completion, so
 * it refuses instead of inserting a second account. This is the same order
 * `VerifyRegistrationPasscode` established — registration first, always — and
 * no path anywhere reaches a registration from the other side.
 *
 * UNIQUENESS IS THE DATABASE'S, NOT THIS CLASS'S
 *
 * There is no "does an account already exist?" pre-check. One would be a race
 * with a window between the read and the insert, and it would quietly become
 * the real rule while the index sat there unexercised. The insert is attempted
 * and the unique indexes arbitrate; only once one has fired does this look at
 * `accounts` — to name WHICH identifier collided, not to decide THAT one did.
 * The same shape `App\Reviews\SubmitReview` uses, and for the same two reasons:
 * the savepoint keeps a failed statement from poisoning the transaction the
 * classification has to read in, and classifying by re-reading rows beats
 * parsing a constraint name that a rename would silently change.
 *
 * A COLLISION IS A REFUSAL, NEVER AN ADOPTION
 *
 * Nothing here attaches the registration to the existing account, merges the
 * two, or overwrites an identifier. An account that already holds this number
 * is somebody's — very likely a member who signed up by phone before a second
 * channel existed — and treating their row as this registration's outcome would
 * hand a stranger their account for the price of one SMS. The transaction
 * unwinds, the existing account is not read for anything but classification,
 * and the registration stays open.
 *
 * NOT REACHABLE FROM OUTSIDE
 *
 * No route resolves this and no controller calls it. It is the domain
 * transaction; the public surface, its error vocabulary and its response shape
 * are the next slice's.
 */
final class CompleteRegistration
{
    public function __construct(private readonly TokenService $tokens) {}

    /**
     * @throws RegistrationCompletionRefused when this registration may not produce an account.
     */
    public function __invoke(Registration $registration, DeviceDescription $device): CompletedRegistration
    {
        return DB::transaction(function () use ($registration, $device): CompletedRegistration {
            // LOCK 1. Before any decision, and before `accounts` is touched.
            $locked = Registration::query()
                ->whereKey($registration->getKey())
                ->lockForUpdate()
                ->first();

            // Gone, expired, or already completed. One refusal for all three:
            // the credential's holder learns that it is finished, not how.
            if (! $locked instanceof Registration || ! $locked->isAdvanceable()) {
                throw RegistrationCompletionRefused::registrationEnded();
            }

            if (! $locked->isFullyProven()) {
                throw RegistrationCompletionRefused::notFullyProven();
            }

            $email = $locked->email;
            $phone = $locked->phone_e164;
            $emailVerifiedAt = $locked->email_verified_at;
            $phoneVerifiedAt = $locked->phone_verified_at;

            if ($email === null || $phone === null || $emailVerifiedAt === null || $phoneVerifiedAt === null) {
                // Unreachable while the two `registrations` CHECK constraints
                // hold: a proof timestamp requires its identifier. Stated
                // rather than assumed, because the alternative is an account
                // built from a value nobody confirmed was there.
                throw RegistrationCompletionRefused::notFullyProven();
            }

            $account = $this->createAccount($email, $emailVerifiedAt, $phone, $phoneVerifiedAt);

            // The consumption. After this the credential resolves to nothing,
            // so it can neither advance this registration nor mint a second
            // session — a client that loses the response below recovers by
            // signing in, not by presenting this again.
            $locked->completed_at = CarbonImmutable::now();
            $locked->save();

            // The caller's instance stops being stale rather than reporting an
            // open registration immediately after completing it.
            $registration->setRawAttributes($locked->getAttributes(), true);

            // Inside the transaction, exactly as `AuthenticateByPhone` does it.
            // `TokenService::issue()` opens none of its own, so this composes
            // without nesting ownership: a failure here unwinds the account and
            // the completion with it.
            return new CompletedRegistration($account, $this->tokens->issue($account, $device));
        });
    }

    /**
     * Writes the account, or turns the collision into a refusal.
     *
     * The insert runs in its own transaction so a unique violation rolls back
     * to a savepoint. PostgreSQL aborts a whole transaction on the first failed
     * statement, so without it the classification below could not read
     * anything — every query would answer "current transaction is aborted",
     * which names no identifier at all.
     *
     * @throws RegistrationCompletionRefused
     */
    private function createAccount(
        string $email,
        CarbonImmutable $emailVerifiedAt,
        string $phone,
        CarbonImmutable $phoneVerifiedAt,
    ): Account {
        $account = new Account;
        $account->email = $email;
        $account->email_verified_at = $emailVerifiedAt;
        $account->phone_e164 = $phone;
        $account->phone_verified_at = $phoneVerifiedAt;

        // `status` is deliberately not set, exactly as in `AuthenticateByPhone`:
        // the column defaults to 'active' and the database CHECK bounds it.
        // A registration does not produce a new kind of account.

        try {
            DB::transaction(static fn () => $account->save());
        } catch (QueryException $collision) {
            throw $this->refuse($collision, $email, $phone);
        }

        // Read back so the returned model carries the defaulted `status` rather
        // than an absent attribute that `isActive()` would read as false.
        $account->refresh();

        return $account;
    }

    /**
     * Which uniqueness rule was tripped, worked out by looking.
     *
     * The number is checked first because it is the identifier every account
     * has ever had: a collision today is overwhelmingly a member who registered
     * by phone before this path existed. When both collide, the order is
     * arbitrary and only one reason can be given anyway.
     *
     * @throws QueryException when neither did — this understands unique
     *                        violations and nothing else, and swallowing the
     *                        rest would report a refusal that did not happen.
     */
    private function refuse(QueryException $collision, string $email, string $phone): RegistrationCompletionRefused
    {
        if (Account::query()->where('phone_e164', $phone)->exists()) {
            return RegistrationCompletionRefused::phoneAlreadyRegistered();
        }

        if (Account::query()->where('email', $email)->exists()) {
            return RegistrationCompletionRefused::emailAlreadyRegistered();
        }

        throw $collision;
    }
}
