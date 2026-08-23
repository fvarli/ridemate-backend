<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Auth\TokenService;
use App\Models\Account;
use App\Models\AccountStatus;
use App\Models\SessionRevocationReason;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

/**
 * The producer that makes `suspended` a real state rather than an unreachable
 * one.
 *
 * A closed beta needs "stop this person signing in" before it opens, and it
 * needs it without an admin panel and without editing rows by hand — the thing
 * this project committed to not doing. Thirty lines of console command is the
 * honest answer at this size.
 *
 * Suspension revokes live sessions as well as setting the status. Without that
 * the member keeps a working access token until it expires, which is exactly
 * the window an operator is trying to close.
 */
final class SuspendAccount extends Command
{
    protected $signature = 'ridemate:account:suspend {phone : The member phone number, in any format}';

    protected $description = 'Suspend an account and revoke its live sessions';

    public function handle(TokenService $tokens): int
    {
        $phone = PhoneNumber::normalize($this->argument('phone'));

        if ($phone === null) {
            $this->error('That is not a valid phone number.');

            return self::FAILURE;
        }

        $account = Account::query()->where('phone_e164', $phone)->first();

        if (! $account instanceof Account) {
            $this->error('No account exists for that number.');

            return self::FAILURE;
        }

        $account->status = AccountStatus::Suspended;
        $account->save();

        $revoked = $tokens->revokeAllFor($account, SessionRevocationReason::AccountSuspended);

        $this->info("Suspended {$account->id}; revoked {$revoked} session(s).");

        return self::SUCCESS;
    }
}
