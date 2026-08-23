<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Account;
use App\Models\AccountStatus;
use App\Support\PhoneNumber;
use Illuminate\Console\Command;

/**
 * The other direction.
 *
 * Not in the original plan, and added because a suspension with no documented
 * way back is a trap: the first mistaken suspension would otherwise be undone
 * by hand-editing a row, which is the practice this tooling exists to avoid.
 *
 * Restoring does NOT return the member's old sessions. Those were revoked and
 * revocation is final; they sign in again. Reviving them would mean a token
 * family that was deliberately killed coming back to life, which is never
 * something an operator should be able to do by accident.
 */
final class RestoreAccount extends Command
{
    protected $signature = 'ridemate:account:restore {phone : The member phone number, in any format}';

    protected $description = 'Return a suspended account to active. Revoked sessions stay revoked.';

    public function handle(): int
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

        $account->status = AccountStatus::Active;
        $account->save();

        $this->info("Restored {$account->id}. Previously revoked sessions remain revoked.");

        return self::SUCCESS;
    }
}
