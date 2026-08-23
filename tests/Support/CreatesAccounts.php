<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\Account;
use App\Models\AccountStatus;
use Carbon\CarbonImmutable;

/**
 * Account creation for tests.
 *
 * Deliberately not a model factory: factories need fakerphp, which Phase 8
 * removed, and random test data is a poor fit for a table whose whole point is
 * that one phone number means one account. Explicit values make the duplicate
 * and normalization tests say what they mean.
 */
trait CreatesAccounts
{
    protected function createAccount(
        string $phone = '+905321234567',
        AccountStatus $status = AccountStatus::Active,
    ): Account {
        $account = new Account;
        $account->phone_e164 = $phone;
        $account->phone_verified_at = CarbonImmutable::now();
        $account->status = $status;
        $account->save();

        return $account;
    }
}
