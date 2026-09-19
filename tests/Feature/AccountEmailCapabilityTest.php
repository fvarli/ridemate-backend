<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AuthenticateByPhone;
use App\Auth\DeviceDescription;
use App\Http\Responses\AccountPayload;
use App\Models\Account;
use App\Support\EmailAddress;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * An account can hold a proven email address, and nothing puts one there.
 *
 * Two properties carry this file, and they pull in opposite directions.
 *
 * The first is that the capability is real and honest: the columns exist, they
 * are unique across accounts, and the database refuses a row that claims an
 * address without the proof or a proof without the address. An account never
 * holds an address it has not verified — there is no "bound but unproven" state
 * here, because that one belongs to `registrations`.
 *
 * The second is that nothing about the capability is switched on. Every account
 * that exists carries two NULLs, and that is the truth about those members
 * rather than a gap in them. Sign-in is untouched, no lookup resolves an
 * account by address, and no response publishes either column. A column is not
 * a feature, and the assertions at the end of this file are what would notice
 * if it quietly became one.
 *
 * These go through the DATABASE rather than the model, as `AccountTest` does:
 * an Eloquent rule is advice, and a constraint is a guarantee.
 */
final class AccountEmailCapabilityTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    private const EMAIL = 'member@ridemate.invalid';

    // ------------------------------------------------------- legacy accounts

    /**
     * CARRIES WEIGHT. Every account alive is a phone-only account, and the
     * migration must leave all of them valid.
     */
    public function test_a_phone_only_account_is_valid_with_both_email_columns_null(): void
    {
        $account = $this->createAccount()->refresh();

        self::assertNull($account->email);
        self::assertNull($account->email_verified_at);
        self::assertTrue($account->isActive());
    }

    /**
     * CARRIES WEIGHT. Nothing was invented for rows that predate the columns.
     *
     * Written as a raw insert of exactly the shape an account had before this
     * migration, so a default, a trigger or a backfill would show up as a
     * non-null value here.
     */
    public function test_a_row_written_in_the_pre_migration_shape_gains_no_email_or_timestamp(): void
    {
        $id = (string) Str::uuid7();

        DB::table('accounts')->insert([
            'id' => $id,
            'phone_e164' => '+905329876543',
            'phone_verified_at' => CarbonImmutable::now(),
            'status' => 'active',
            'created_at' => CarbonImmutable::now(),
            'updated_at' => CarbonImmutable::now(),
        ]);

        $row = DB::table('accounts')->where('id', $id)->first();

        self::assertNotNull($row);
        self::assertNull($row->email);
        self::assertNull($row->email_verified_at);
    }

    /** Many accounts may have no address, which is every account today. */
    public function test_several_accounts_may_all_have_no_email(): void
    {
        $this->createAccount('+905321234567');
        $this->createAccount('+905329876543');
        $this->createAccount('+905327654321');

        self::assertSame(3, Account::query()->whereNull('email')->count());
    }

    /** The phone columns are untouched, and stay required. */
    public function test_the_phone_columns_are_still_required(): void
    {
        $this->expectException(QueryException::class);

        DB::table('accounts')->insert([
            'id' => (string) Str::uuid7(),
            'phone_e164' => null,
            'phone_verified_at' => null,
            'status' => 'active',
            'email' => self::EMAIL,
            'email_verified_at' => CarbonImmutable::now(),
        ]);
    }

    // ------------------------------------------------------- the capability

    public function test_a_verified_email_can_be_stored_alongside_its_timestamp(): void
    {
        $account = $this->createAccount();

        $this->attachEmail($account->id, self::EMAIL);

        $fresh = Account::query()->findOrFail($account->id);
        self::assertSame(self::EMAIL, $fresh->email);
        self::assertNotNull($fresh->email_verified_at);
    }

    /**
     * CARRIES WEIGHT. One address, one account.
     *
     * Case-insensitive identity falls out of the STORED form rather than out of
     * a functional index: `EmailAddress` lowercases the whole address, so two
     * spellings canonicalize to one string and the plain unique index is enough.
     * The test canonicalizes both spellings the way every writer must, which is
     * the property worth proving.
     */
    public function test_two_accounts_cannot_hold_one_canonical_address(): void
    {
        $first = $this->createAccount('+905321234567');
        $second = $this->createAccount('+905329876543');

        $this->attachEmail($first->id, self::EMAIL);

        $shouted = EmailAddress::normalize('  Member@RideMate.Invalid  ');
        self::assertSame(self::EMAIL, $shouted, 'the canonicalizer stopped folding case');

        $this->expectException(QueryException::class);

        $this->attachEmail($second->id, $shouted);
    }

    /**
     * CARRIES WEIGHT. An account never holds an address it has not proven.
     *
     * Two-way here, where `registrations` is one-way: a registration binds a
     * destination before proving it, because that is what the passcode is sent
     * to. An account has no such in-between state.
     */
    public function test_an_email_without_its_verification_timestamp_is_refused(): void
    {
        $account = $this->createAccount();

        $this->expectException(QueryException::class);

        DB::table('accounts')->where('id', $account->id)->update([
            'email' => self::EMAIL,
            'email_verified_at' => null,
        ]);
    }

    /** And the reverse: a proof with nothing to have proven. */
    public function test_a_verification_timestamp_without_an_email_is_refused(): void
    {
        $account = $this->createAccount();

        $this->expectException(QueryException::class);

        DB::table('accounts')->where('id', $account->id)->update([
            'email' => null,
            'email_verified_at' => CarbonImmutable::now(),
        ]);
    }

    /** Clearing both together stays legal — the pair is what the rule is about. */
    public function test_both_columns_may_be_cleared_together(): void
    {
        $account = $this->createAccount();
        $this->attachEmail($account->id, self::EMAIL);

        DB::table('accounts')->where('id', $account->id)->update([
            'email' => null,
            'email_verified_at' => null,
        ]);

        self::assertNull(Account::query()->findOrFail($account->id)->email);
    }

    /**
     * Neither column may be mass-assigned, for the reason the phone cannot —
     * and the model does not discard the attempt quietly, it refuses it.
     *
     * A fillable verification timestamp would be a way to claim an address
     * without proving it, which is the one thing the column must not allow.
     */
    public function test_the_email_columns_cannot_be_mass_assigned(): void
    {
        $this->expectException(MassAssignmentException::class);

        (new Account)->fill([
            'email' => self::EMAIL,
            'email_verified_at' => CarbonImmutable::now(),
        ]);
    }

    // ------------------------------------------------- nothing is switched on

    /**
     * CARRIES WEIGHT, AND IS THE POINT OF THE WHOLE SLICE.
     *
     * Sign-in is what it was. A first-time member still becomes an account from
     * a verified number alone, and that account carries no address — so nothing
     * about the new columns made phone-only registration incomplete.
     */
    public function test_phone_sign_in_still_creates_an_account_with_no_email(): void
    {
        $account = app(AuthenticateByPhone::class);

        $pair = $account('+905321234567', new DeviceDescription(null, 'test', null));

        self::assertNotSame('', $pair->accessToken);

        $created = Account::query()->firstOrFail();
        self::assertSame('+905321234567', $created->phone_e164);
        self::assertNull($created->email);
        self::assertNull($created->email_verified_at);
    }

    /**
     * CARRIES WEIGHT. No column reached a response.
     *
     * `AccountPayload` names its fields one by one precisely so that a
     * migration cannot publish something nobody decided to publish. This is
     * that guarantee, asserted rather than trusted.
     */
    public function test_the_account_response_does_not_publish_the_new_columns(): void
    {
        $payload = AccountPayload::from(
            $this->withEmail($this->createAccount()),
        );

        self::assertSame(
            ['id', 'phone_e164', 'phone_verified_at', 'status', 'created_at'],
            array_keys($payload['account']),
        );

        $encoded = (string) json_encode($payload);
        self::assertStringNotContainsString(self::EMAIL, $encoded);
        self::assertStringNotContainsString('email', $encoded);
    }

    /**
     * CARRIES WEIGHT. There is no way to sign in with an address.
     *
     * The passcode endpoints take a phone number and nothing else. An account
     * that holds a verified address is not reachable through them by that
     * address — the request does not validate, because there is no field for
     * it, and no lookup anywhere resolves an account by email.
     */
    public function test_no_endpoint_accepts_an_email_as_a_sign_in_identifier(): void
    {
        $this->withEmail($this->createAccount());

        $this->postJson('/api/v1/auth/otp', ['email' => self::EMAIL])
            ->assertStatus(422);

        $this->postJson('/api/v1/auth/otp/verify', ['email' => self::EMAIL, 'code' => '000000'])
            ->assertStatus(422);
    }

    // --------------------------------------------------------------- helpers

    private function attachEmail(string $accountId, string $email): void
    {
        DB::table('accounts')->where('id', $accountId)->update([
            'email' => $email,
            'email_verified_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * An account carrying a proven address, written directly.
     *
     * Directly, because nothing in the application writes these columns — that
     * is what makes this a capability slice. The tests above still need the
     * state a future completion will produce.
     */
    private function withEmail(Account $account): Account
    {
        $this->attachEmail($account->id, self::EMAIL);

        return Account::query()->findOrFail($account->id);
    }
}
