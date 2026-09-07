<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Profile;
use App\Profiles\DisplayName;
use App\Profiles\SaveProfile;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * Two first-time saves arriving together produce one profile and no 500.
 *
 * WHY THIS CANNOT BE A REFRESHDATABASE TEST
 *
 * The failure only exists across a commit boundary: one request has to have
 * committed its insert before the other's reaches the unique index. Inside a
 * single wrapping transaction the second write would simply see the first, and
 * the constraint would never fire — the test would pass while proving nothing.
 * Same shape and same reason as OtpIssuanceLockingTest and RefreshLockingTest.
 *
 * HOW THE RACE IS MADE DETERMINISTIC
 *
 * Rather than hoping two threads interleave, the competing row is committed on
 * a second connection from inside the `creating` hook — that is, after
 * SaveProfile has looked and found nothing, and before its own INSERT runs.
 * That is exactly the window the race lives in, hit on purpose instead of by
 * chance. The second connection commits independently, so the row survives the
 * rollback of the transaction whose insert is about to fail.
 *
 * WHAT MUST BE TRUE AFTERWARDS
 *
 * One row, not two. No unhandled exception reaching the caller. And the losing
 * request reports an UPDATE, because by the time it managed to write, the
 * profile genuinely already existed — which keeps the endpoint's 201/200
 * honest for each request's own outcome rather than for the pair.
 */
final class ProfileCreateRaceTest extends TestCase
{
    use CreatesAccounts;

    private const SECOND = 'rm_profile_concurrent';

    protected function setUp(): void
    {
        parent::setUp();

        Artisan::call('migrate', ['--force' => true]);
        config(['database.connections.'.self::SECOND => config('database.connections.pgsql')]);

        $this->truncate();
    }

    protected function tearDown(): void
    {
        Profile::flushEventListeners();
        $this->truncate();
        DB::disconnect(self::SECOND);

        parent::tearDown();
    }

    private function truncate(): void
    {
        DB::statement('truncate table profiles, auth_tokens, auth_sessions, accounts, otp_challenges cascade');
    }

    /**
     * Commits a profile for this account on a different connection, once.
     *
     * Once, because the retry must find a settled world. A hook that fired
     * again would be simulating a second racing request rather than the
     * aftermath of the first, and no such request exists.
     */
    private function commitCompetingProfileDuringCreate(string $accountId, string $name): void
    {
        $fired = false;

        Profile::creating(function () use (&$fired, $accountId, $name): void {
            if ($fired) {
                return;
            }

            $fired = true;

            DB::connection(self::SECOND)->table('profiles')->insert([
                'id' => (string) Str::uuid7(),
                'account_id' => $accountId,
                'display_name' => $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });
    }

    /**
     * CARRIES WEIGHT. The whole point of the commit.
     */
    public function test_losing_the_create_race_converges_on_one_profile(): void
    {
        $account = $this->createAccount();
        $this->commitCompetingProfileDuringCreate($account->id, 'Winner Name');

        $saved = (new SaveProfile)($account, DisplayName::fromInput('Ayşe Demir'));

        // One profile, and it is the one the winner created — rewritten to what
        // this request asked for, which is what a target-state write means.
        self::assertSame(1, Profile::query()->count());
        self::assertSame('Ayşe Demir', Profile::query()->sole()->display_name);

        // Truthful for this request: the row existed before it wrote.
        self::assertFalse($saved->created);
    }

    /**
     * The failure this replaces, named so it cannot come back quietly: without
     * the catch the member gets a 500 for an operation that in fact succeeded.
     */
    public function test_the_race_raises_nothing_at_the_caller(): void
    {
        $account = $this->createAccount();
        $this->commitCompetingProfileDuringCreate($account->id, 'Winner Name');

        $saved = (new SaveProfile)($account, DisplayName::fromInput('Ali Can'));

        self::assertSame('Ali Can', $saved->profile->display_name);
        self::assertSame($account->id, $saved->profile->account_id);
    }

    /**
     * CARRIES WEIGHT. The recovery is for ONE race, not for uniqueness in
     * general.
     *
     * A duplicate primary key is a defect — an id generator repeating itself,
     * or a caller reusing one. Retrying it would either fail identically or,
     * on a second roll of the dice, succeed and report a create that hid a
     * broken identifier. Either way the member would be told nothing.
     *
     * The violation is forced by giving the new profile an id that already
     * belongs to ANOTHER account's profile, so the constraint that breaks is
     * `profiles_pkey` and this account still has no profile of its own —
     * exactly the shape the recovery must not mistake for the race.
     */
    public function test_an_unrelated_unique_violation_is_not_swallowed(): void
    {
        $other = $this->createAccount('+905329876543');
        $taken = (new SaveProfile)($other, DisplayName::fromInput('Ali Can'))->profile->id;

        $account = $this->createAccount();

        // Counted, because the exception alone proves nothing here: a recovery
        // that retried this violation would fail again on the second attempt
        // and throw the same class, so `expectException` passes either way.
        // The number of attempts is what actually distinguishes "rethrown
        // immediately" from "retried, then given up on".
        $attempts = 0;
        Profile::creating(function (Profile $profile) use ($taken, &$attempts): void {
            $attempts++;
            $profile->id = $taken;
        });

        $thrown = null;

        try {
            (new SaveProfile)($account, DisplayName::fromInput('Ayşe Demir'));
        } catch (UniqueConstraintViolationException $e) {
            $thrown = $e;
        }

        self::assertInstanceOf(UniqueConstraintViolationException::class, $thrown);
        self::assertSame(1, $attempts, 'an unrelated violation must not be retried');

        // And it is rethrown unchanged: the primary key, not account_id.
        self::assertSame(['id'], $thrown->columns);

        // The other account's profile is untouched, and this account still has
        // none: nothing was quietly written on the way past.
        self::assertSame(1, Profile::query()->count());
        self::assertSame('Ali Can', Profile::query()->sole()->display_name);
        self::assertFalse(
            Profile::query()->where('account_id', $account->id)->exists(),
        );
    }

    /**
     * The mirror image, and the reason the counter above is trustworthy: the
     * real race DOES retry, so the hook fires twice.
     */
    public function test_the_race_is_retried_exactly_once(): void
    {
        $account = $this->createAccount();
        $attempts = 0;
        $competed = false;

        Profile::creating(function () use (&$attempts, &$competed, $account): void {
            $attempts++;

            if ($competed) {
                return;
            }

            $competed = true;

            DB::connection(self::SECOND)->table('profiles')->insert([
                'id' => (string) Str::uuid7(),
                'account_id' => $account->id,
                'display_name' => 'Winner Name',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        (new SaveProfile)($account, DisplayName::fromInput('Ayşe Demir'));

        // Once for the attempt that lost, and no second create: the retry found
        // the winner's row and updated it instead of inserting again.
        self::assertSame(1, $attempts);
        self::assertSame(1, Profile::query()->count());
    }

    /**
     * An uncontended create still reports a create. The retry path must not
     * make every save look like an update.
     */
    public function test_an_uncontended_create_still_reports_a_create(): void
    {
        $account = $this->createAccount();

        $saved = (new SaveProfile)($account, DisplayName::fromInput('Ayşe Demir'));

        self::assertTrue($saved->created);
        self::assertSame(1, Profile::query()->count());
    }
}
