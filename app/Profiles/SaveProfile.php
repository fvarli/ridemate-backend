<?php

declare(strict_types=1);

namespace App\Profiles;

use App\Models\Account;
use App\Models\Profile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Puts an account's public identity at the state the member asked for.
 *
 * A TARGET-STATE WRITE, WHICH IS WHY IT NEEDS NO IDEMPOTENCY KEY
 *
 * The request names what the profile should say, not a change to apply. Sending
 * it twice leaves the same profile with the same name, so a retry over a
 * dropped connection is safe by the shape of the operation — tier 2 of
 * docs/api-conventions.md, the same reasoning that lets route cancellation go
 * without a key. What the caller still learns is which of the two things
 * happened, because the endpoint answers 201 for a create and 200 for an
 * update, and only this action is in a position to know.
 *
 * THE CREATE RACE, AND WHY THE DATABASE DECIDES IT
 *
 * Two first-time saves can arrive together: both look, both find nothing, and
 * both try to insert. `profiles.account_id` is unique, so the database refuses
 * the second — which is the correct outcome and the wrong response, because an
 * unhandled constraint violation reaches the member as a 500 for an operation
 * that actually succeeded.
 *
 * So the violation is caught and the whole thing runs once more. By then the
 * winner's row is committed, the retry finds it, and the loser's request
 * truthfully reports an update: from that request's point of view the profile
 * already existed by the time it wrote. One retry is enough because there is no
 * path that deletes a profile — once the row exists it stays, so the second
 * attempt cannot lose the same race twice.
 *
 * The check-then-insert is not moved into an upsert. An upsert would collapse
 * created and updated into one statement and lose exactly the distinction the
 * status code is made of.
 */
final class SaveProfile
{
    public function __invoke(Account $account, DisplayName $name): SavedProfile
    {
        try {
            return DB::transaction(fn (): SavedProfile => $this->write($account, $name));
        } catch (UniqueConstraintViolationException $violation) {
            if (! $this->isFirstProfileRace($account, $violation)) {
                // Some other uniqueness broke. Retrying would either repeat the
                // same failure or, worse, succeed for a reason nobody intended
                // — and either way it would hide a defect behind a status code
                // that says everything worked.
                throw $violation;
            }

            // Lost the race. PostgreSQL aborts the transaction the failed
            // insert was in, so this cannot be retried inside it — the whole
            // unit of work runs again on a clean transaction instead.
            return DB::transaction(fn (): SavedProfile => $this->write($account, $name));
        }
    }

    /**
     * Whether this violation is the one concurrent first-profile write.
     *
     * `columns` is a first-class field on the framework's exception rather than
     * something parsed here, so `account_id` names the constraint that broke
     * without this file knowing anything about PostgreSQL's error text.
     *
     * The fallback matters because that field is only as good as the driver
     * message it was read from, and PostgreSQL localizes those: a server
     * running with a non-English `lc_messages` hands back an empty list. So
     * when the framework cannot name the columns, the question is asked of the
     * database instead — a profile now exists for this account, which is the
     * state the race leaves behind and the only state in which repeating the
     * write is the right answer. An unrelated violation with no profile in
     * place still falls through and is rethrown.
     */
    private function isFirstProfileRace(
        Account $account,
        UniqueConstraintViolationException $violation,
    ): bool {
        if ($violation->columns !== []) {
            return in_array('account_id', $violation->columns, true);
        }

        return Profile::query()->where('account_id', $account->id)->exists();
    }

    private function write(Account $account, DisplayName $name): SavedProfile
    {
        // Locked, so two updates to the same profile serialize rather than
        // interleave. There is nothing to lock when the row does not exist yet,
        // which is the case the unique constraint above covers.
        $existing = Profile::query()
            ->where('account_id', $account->id)
            ->lockForUpdate()
            ->first();

        if ($existing instanceof Profile) {
            $existing->display_name = $name->value;
            $existing->save();

            return new SavedProfile($existing, created: false);
        }

        $profile = new Profile;
        // Assigned directly rather than mass-assigned: ownership comes from the
        // authenticated account, and `account_id` is deliberately not fillable.
        $profile->account_id = $account->id;
        $profile->display_name = $name->value;
        $profile->save();

        return new SavedProfile($profile, created: true);
    }
}
