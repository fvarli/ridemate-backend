<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Profile;
use App\Profiles\DisplayName;
use App\Profiles\SaveProfile;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * One profile per account, and what saving one twice means.
 *
 * The API is Phase 11's next commit; this proves the domain underneath it, so
 * the endpoint has nothing left to decide except its status code.
 */
final class ProfilePersistenceTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    public function test_a_first_save_creates_the_profile(): void
    {
        $account = $this->createAccount();

        $saved = (new SaveProfile)($account, DisplayName::fromInput('Ayşe Demir'));

        self::assertTrue($saved->created);
        self::assertSame('Ayşe Demir', $saved->profile->display_name);
        self::assertSame($account->id, $saved->profile->account_id);
        self::assertSame(1, Profile::query()->count());
    }

    public function test_a_second_save_updates_rather_than_creating(): void
    {
        $account = $this->createAccount();
        $action = new SaveProfile;

        $first = $action($account, DisplayName::fromInput('Ayşe Demir'));
        $second = $action($account, DisplayName::fromInput('Ayşe Yılmaz'));

        self::assertTrue($first->created);
        self::assertFalse($second->created);
        // The same row, renamed — not a second profile.
        self::assertSame($first->profile->id, $second->profile->id);
        self::assertSame('Ayşe Yılmaz', $second->profile->fresh()?->display_name);
        self::assertSame(1, Profile::query()->count());
    }

    /**
     * A target-state write: sending the same name again changes nothing and is
     * still not a create. This is what lets the endpoint go without an
     * Idempotency-Key.
     */
    public function test_saving_the_same_name_again_is_an_update_and_no_second_row(): void
    {
        $account = $this->createAccount();
        $action = new SaveProfile;

        $action($account, DisplayName::fromInput('Ayşe Demir'));
        $repeat = $action($account, DisplayName::fromInput('Ayşe Demir'));

        self::assertFalse($repeat->created);
        self::assertSame('Ayşe Demir', $repeat->profile->display_name);
        self::assertSame(1, Profile::query()->count());
    }

    public function test_the_stored_name_is_the_trimmed_one(): void
    {
        $account = $this->createAccount();

        (new SaveProfile)($account, DisplayName::fromInput('   Ayşe Demir   '));

        self::assertSame('Ayşe Demir', Profile::query()->sole()->display_name);
    }

    public function test_two_accounts_keep_separate_profiles(): void
    {
        $one = $this->createAccount('+905321234567');
        $two = $this->createAccount('+905329876543');
        $action = new SaveProfile;

        $action($one, DisplayName::fromInput('Ayşe Demir'));
        $action($two, DisplayName::fromInput('Ali Can'));

        self::assertSame(2, Profile::query()->count());
        self::assertSame('Ayşe Demir', $one->profile()->sole()->display_name);
        self::assertSame('Ali Can', $two->profile()->sole()->display_name);
    }

    /**
     * CARRIES WEIGHT. The constraint is the database's, not the application's.
     *
     * SaveProfile is careful, but "one profile per account" must hold against
     * anything that writes the table — a console command, a future importer, a
     * mistake. This inserts around the action deliberately.
     */
    public function test_the_database_refuses_a_second_profile_for_one_account(): void
    {
        $account = $this->createAccount();
        (new SaveProfile)($account, DisplayName::fromInput('Ayşe Demir'));

        $this->expectException(QueryException::class);

        DB::table('profiles')->insert([
            'id' => '01991a00-0000-7000-8000-0000000000ff',
            'account_id' => $account->id,
            'display_name' => 'Someone Else',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Initials are derived, never stored, so they cannot fall out of step with
     * the name they belong to.
     */
    public function test_initials_are_not_a_column(): void
    {
        self::assertNotContains(
            'initials',
            DB::getSchemaBuilder()->getColumnListing('profiles'),
        );
    }

    public function test_initials_follow_the_name_after_a_rename(): void
    {
        $account = $this->createAccount();
        $action = new SaveProfile;

        $action($account, DisplayName::fromInput('Ayşe Demir'));
        self::assertSame('AD', Profile::query()->sole()->initials());

        $action($account, DisplayName::fromInput('irem yılmaz'));
        self::assertSame('İY', Profile::query()->sole()->initials());
    }

    /**
     * Ownership comes from the authenticated account. A payload naming someone
     * else's account must not be able to write their profile.
     */
    public function test_account_id_is_not_mass_assignable(): void
    {
        $profile = new Profile;
        $profile->fill([
            'account_id' => '01991a00-0000-7000-8000-0000000000aa',
            'display_name' => 'Ayşe Demir',
        ]);

        // The attribute is never set at all, rather than set and overwritten
        // later. `display_name` is asserted alongside it so the test cannot
        // pass by fill() having done nothing.
        self::assertArrayNotHasKey('account_id', $profile->getAttributes());
        self::assertSame('Ayşe Demir', $profile->display_name);
    }

    public function test_deleting_an_account_takes_its_profile_with_it(): void
    {
        $account = $this->createAccount();
        (new SaveProfile)($account, DisplayName::fromInput('Ayşe Demir'));

        $account->delete();

        self::assertSame(0, Profile::query()->count());
    }
}
