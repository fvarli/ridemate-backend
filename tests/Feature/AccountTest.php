<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AccountStatus;
use App\Support\PhoneNumber;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * The account row, and the guarantees the database makes about it.
 *
 * These assertions deliberately go through the DATABASE rather than the model.
 * An Eloquent rule is advice; a constraint is a guarantee, and the difference
 * matters because the console commands and future admin tooling will write to
 * these tables without passing through a request.
 */
final class AccountTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    public function test_an_account_is_created_active(): void
    {
        $account = $this->createAccount()->refresh();

        self::assertSame(AccountStatus::Active, $account->status);
        self::assertTrue($account->isActive());
    }

    /**
     * The whole reason phone numbers are normalized before they arrive.
     *
     * Two rows for one human is not a duplicate record; it is two identities,
     * two trust histories and two sets of trips for the same person.
     */
    public function test_the_same_phone_number_cannot_be_registered_twice(): void
    {
        $this->createAccount('+905321234567');

        $this->expectException(QueryException::class);
        $this->createAccount('+905321234567');
    }

    /**
     * The status set is closed at the database, not merely in PHP.
     *
     * A migration, a console command or a manual UPDATE all bypass the enum
     * cast. If `deleted` is ever wanted it arrives as a decision and a
     * migration, never as a stray string that happened to be written.
     */
    public function test_the_database_refuses_a_status_outside_the_enum(): void
    {
        $this->createAccount();

        $this->expectException(QueryException::class);
        DB::table('accounts')->update(['status' => 'deleted']);
    }

    /**
     * HasUuids is the v7 trait, and v7 is chosen for its ordering.
     *
     * If someone swaps in HasVersion4Uuids — or writes their own id — this
     * fails, because random ids do not sort by creation time. That ordering is
     * the entire reason for the choice: it keeps inserts appending to the index
     * instead of scattering across it.
     */
    public function test_identifiers_are_time_ordered_uuid_v7(): void
    {
        $first = $this->createAccount('+905321234567');
        $second = $this->createAccount('+905329876543');

        self::assertTrue(Str::isUuid($first->id));
        self::assertSame(7, (int) $first->id[14], 'the version nibble should be 7');
        self::assertLessThan(
            $second->id,
            $first->id,
            'v7 identifiers should sort in creation order',
        );
    }

    /**
     * The two halves of "phone identity", proven together.
     *
     * PhoneNumberTest shows that these spellings normalize to one string, and
     * the unique constraint above shows that one string cannot be stored twice.
     * Neither fact is worth much alone: normalization without the constraint
     * still permits duplicates, and the constraint without normalization guards
     * formatting rather than identity.
     *
     * This is the assertion that actually says "one human, one account", and it
     * goes through the normalizer rather than around it — which is also how the
     * request path will reach the database.
     */
    public function test_two_spellings_of_one_number_cannot_become_two_accounts(): void
    {
        $first = PhoneNumber::normalize('0532 123 45 67');
        $second = PhoneNumber::normalize('+90 532 123 45 67');

        self::assertNotNull($first);
        self::assertNotNull($second);

        $this->createAccount($first);

        $this->expectException(QueryException::class);
        $this->createAccount($second);
    }

    /**
     * Suspension is a state the application can actually reach and read back.
     */
    public function test_an_account_can_be_suspended(): void
    {
        $account = $this->createAccount();
        $account->status = AccountStatus::Suspended;
        $account->save();

        self::assertFalse($account->refresh()->isActive());
    }
}
