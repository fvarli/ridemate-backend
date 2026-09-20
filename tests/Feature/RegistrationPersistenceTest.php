<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Registration;
use App\Otp\OtpChannel;
use App\Registration\InvalidIdentifier;
use App\Registration\RegistrationService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * The registration row: what the database guarantees, and what it deliberately
 * does not.
 *
 * The interesting assertions here are the two that pull in opposite
 * directions. A verification timestamp without its identifier is refused,
 * because such a row claims to have proven nothing in particular. But two
 * in-flight registrations naming one address are ALLOWED, because an unproven
 * registration confers nothing and a uniqueness rule here would let anyone bar
 * an address they do not own. Ownership is decided at completion, against
 * `accounts`, and nowhere else.
 */
final class RegistrationPersistenceTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    private const EMAIL = 'member@ridemate.invalid';

    private const PHONE = '+905321234567';

    private RegistrationService $registrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registrations = app(RegistrationService::class);
    }

    // --------------------------------------------------------- canonical form

    /**
     * CARRIES WEIGHT. One identity, one string.
     *
     * The value stored here has to be byte-identical to the one
     * `otp_challenges.destination` will hold, or the advisory lock, the partial
     * unique index and the HMAC are all keyed on an identity this row does not
     * name. So binding goes through the same `EmailAddress` the OTP capability
     * normalizes with, rather than through a second opinion.
     */
    public function test_an_email_is_stored_in_its_canonical_form(): void
    {
        $registration = $this->started();

        $this->registrations->bind($registration, OtpChannel::Email, '  Member@RideMate.Invalid  ');

        self::assertSame(self::EMAIL, $registration->fresh()?->email);
    }

    /** The phone counterpart, through `PhoneNumber` and for the same reason. */
    public function test_a_phone_number_is_stored_in_its_canonical_form(): void
    {
        $registration = $this->started();

        $this->registrations->bind($registration, OtpChannel::Sms, '0532 123 45 67');

        self::assertSame(self::PHONE, $registration->fresh()?->phone_e164);
    }

    /**
     * An unparseable identifier never reaches the row.
     *
     * Thrown rather than refused, because a caller reaching this point has
     * already validated its own input — see `InvalidIdentifier`.
     */
    public function test_an_unparseable_email_is_refused_and_nothing_is_written(): void
    {
        $registration = $this->started();

        $this->expectException(InvalidIdentifier::class);

        try {
            $this->registrations->bind($registration, OtpChannel::Email, 'not an address');
        } finally {
            self::assertNull($registration->fresh()?->email);
        }
    }

    public function test_an_unparseable_phone_number_is_refused_and_nothing_is_written(): void
    {
        $registration = $this->started();

        $this->expectException(InvalidIdentifier::class);

        try {
            $this->registrations->bind($registration, OtpChannel::Sms, '+90 999 999 99 99');
        } finally {
            self::assertNull($registration->fresh()?->phone_e164);
        }
    }

    /**
     * CARRIES WEIGHT. Once bound, a destination stays bound.
     *
     * S4a allowed a correction while the destination was unproven. Nothing ever
     * required it — there is no public registration surface and no client flow
     * — and what it bought was a window in which a challenge already sent to
     * one address outlives the registration naming it. A member who mistyped
     * starts another registration instead; they are cheap and nothing is unique
     * across them.
     */
    public function test_a_bound_destination_cannot_be_changed(): void
    {
        $registration = $this->started();

        $this->registrations->bind($registration, OtpChannel::Email, self::EMAIL);

        $this->expectException(RuntimeException::class);

        try {
            $this->registrations->bind($registration, OtpChannel::Email, 'somebody-else@ridemate.invalid');
        } finally {
            self::assertSame(self::EMAIL, $registration->fresh()?->email);
        }
    }

    /** Binding the same canonical value again is a no-op, because a resend must work. */
    public function test_rebinding_the_same_destination_is_accepted(): void
    {
        $registration = $this->started();

        $this->registrations->bind($registration, OtpChannel::Email, self::EMAIL);
        $this->registrations->bind($registration, OtpChannel::Email, '  Member@RideMate.Invalid  ');

        self::assertSame(self::EMAIL, $registration->fresh()?->email);
    }

    /**
     * CARRIES WEIGHT. A proof belongs to the destination that earned it.
     *
     * Rebinding after verification would move a proof to an address nobody
     * proved, which is the one thing this aggregate exists to prevent. Nothing
     * in this slice sets the timestamp, so the test writes it directly to reach
     * the state the guard defends.
     */
    public function test_a_proven_identifier_cannot_be_rebound(): void
    {
        $registration = $this->started();
        $this->registrations->bind($registration, OtpChannel::Email, self::EMAIL);
        $this->markProven($registration->id, 'email_verified_at');

        $this->expectException(RuntimeException::class);

        try {
            $this->registrations->bind(
                Registration::query()->findOrFail($registration->id),
                OtpChannel::Email,
                'somebody-else@ridemate.invalid',
            );
        } finally {
            self::assertSame(self::EMAIL, $registration->fresh()?->email);
        }
    }

    /** A registration that has ended cannot be advanced, and binding is advancing. */
    public function test_an_ended_registration_cannot_be_bound(): void
    {
        $registration = $this->started();

        DB::table('registrations')
            ->where('id', $registration->id)
            ->update(['expires_at' => CarbonImmutable::now()->subSecond()]);

        $this->expectException(RuntimeException::class);

        $this->registrations->bind(
            Registration::query()->findOrFail($registration->id),
            OtpChannel::Email,
            self::EMAIL,
        );
    }

    // ------------------------------------------------- no in-flight uniqueness

    /**
     * CARRIES WEIGHT, IN THE OTHER DIRECTION.
     *
     * The obvious "one active registration per address" index is a lockout: its
     * predicate could not mention `expires_at`, because `now()` is not
     * IMMUTABLE, so an abandoned attempt would bar an address until something
     * pruned it — and it would buy nothing, because an unproven registration
     * confers nothing. Two people racing on one address is not a conflict until
     * one of them finishes.
     */
    public function test_two_in_flight_registrations_may_bind_the_same_email(): void
    {
        $first = $this->started();
        $second = $this->started();

        $this->registrations->bind($first, OtpChannel::Email, self::EMAIL);
        $this->registrations->bind($second, OtpChannel::Email, self::EMAIL);

        self::assertSame(2, Registration::query()->where('email', self::EMAIL)->count());
    }

    public function test_two_in_flight_registrations_may_bind_the_same_phone(): void
    {
        $first = $this->started();
        $second = $this->started();

        $this->registrations->bind($first, OtpChannel::Sms, self::PHONE);
        $this->registrations->bind($second, OtpChannel::Sms, self::PHONE);

        self::assertSame(2, Registration::query()->where('phone_e164', self::PHONE)->count());
    }

    // ------------------------------------------------------ database invariants

    /**
     * CARRIES WEIGHT. A proof timestamp without its identifier is a row
     * claiming to have proven nothing in particular.
     *
     * Asserted against the database rather than the application, because the
     * application is not the guarantee: a console command, a future migration
     * or a manual UPDATE would all bypass a check written in PHP.
     */
    public function test_an_email_proof_without_an_email_is_refused(): void
    {
        $registration = $this->started();

        $this->expectException(QueryException::class);

        $this->markProven($registration->id, 'email_verified_at');
    }

    public function test_a_phone_proof_without_a_phone_is_refused(): void
    {
        $registration = $this->started();

        $this->expectException(QueryException::class);

        $this->markProven($registration->id, 'phone_verified_at');
    }

    /** Both proofs are legitimate once both identifiers are named. */
    public function test_both_proofs_are_accepted_once_both_identifiers_are_bound(): void
    {
        $registration = $this->started();
        $this->registrations->bind($registration, OtpChannel::Email, self::EMAIL);
        $this->registrations->bind($registration, OtpChannel::Sms, self::PHONE);

        $this->markProven($registration->id, 'email_verified_at');
        $this->markProven($registration->id, 'phone_verified_at');

        self::assertTrue(Registration::query()->findOrFail($registration->id)->isFullyProven());
    }

    // ------------------------------------------------- completion provenance

    /**
     * CARRIES WEIGHT. An in-flight registration names no account and claims no
     * completion, and the two are one state rather than two.
     */
    public function test_an_uncompleted_registration_has_neither_a_linkage_nor_a_completion(): void
    {
        $registration = $this->started();

        self::assertNull($registration->account_id);
        self::assertNull($registration->completed_at);
    }

    /**
     * CARRIES WEIGHT. A completion that names no account is the provenance gap
     * the column exists to close, and the database refuses to represent it.
     *
     * Asserted against the database rather than the application, for the reason
     * the proof checks above are: a console command, a future migration or a
     * manual UPDATE would all bypass a check written in PHP.
     */
    public function test_a_completion_without_its_account_is_refused(): void
    {
        $registration = $this->started();

        $this->expectException(QueryException::class);

        DB::table('registrations')->where('id', $registration->id)->update([
            'account_id' => null,
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    /** And the reverse: an account claimed by a registration that never finished. */
    public function test_a_linkage_without_its_completion_is_refused(): void
    {
        $registration = $this->started();
        $account = $this->createAccount();

        $this->expectException(QueryException::class);

        DB::table('registrations')->where('id', $registration->id)->update([
            'account_id' => $account->id,
            'completed_at' => null,
        ]);
    }

    /** Written together, they are accepted — which is the only legal pair. */
    public function test_an_account_and_a_completion_are_accepted_together(): void
    {
        $registration = $this->started();
        $account = $this->createAccount();

        $this->completeWith($registration->id, $account->id);

        $fresh = Registration::query()->findOrFail($registration->id);
        self::assertSame($account->id, $fresh->account_id);
        self::assertNotNull($fresh->completed_at);
    }

    /**
     * CARRIES WEIGHT. One account is the product of at most one registration.
     *
     * Completion only ever inserts a fresh account and refuses every collision
     * rather than adopting an existing row, so this cannot happen today. The
     * constraint is what turns "it cannot" into "it did not".
     */
    public function test_two_registrations_cannot_claim_one_account(): void
    {
        $first = $this->started();
        $second = $this->started();
        $account = $this->createAccount();

        $this->completeWith($first->id, $account->id);

        $this->expectException(QueryException::class);

        $this->completeWith($second->id, $account->id);
    }

    /** Provenance has to name an account that exists. */
    public function test_a_linkage_to_an_unknown_account_is_refused(): void
    {
        $registration = $this->started();

        $this->expectException(QueryException::class);

        $this->completeWith($registration->id, (string) Str::uuid7());
    }

    /**
     * CARRIES WEIGHT. RESTRICT, not CASCADE and not SET NULL.
     *
     * Deleting the account must not silently delete the record of where it came
     * from, and must not blank the one column that holds it. No deletion flow
     * exists, so this refuses nothing today; it forces the question to be
     * answered by whoever ships deletion rather than resolved by a default
     * nobody chose. It decides nothing about registration retention, which
     * remains under legal review — and note that the registration row itself is
     * as deletable as it ever was.
     */
    public function test_an_account_cannot_be_deleted_while_a_registration_names_it(): void
    {
        $registration = $this->started();
        $account = $this->createAccount();
        $this->completeWith($registration->id, $account->id);

        try {
            // In a savepoint: PostgreSQL aborts a whole transaction on the
            // first failed statement, and RefreshDatabase is holding one, so
            // the assertions below could not otherwise run.
            DB::transaction(static fn () => DB::table('accounts')->where('id', $account->id)->delete());
            self::fail('the account was deleted out from under its provenance');
        } catch (QueryException) {
            // expected
        }

        self::assertNotNull(Account::query()->find($account->id));
        self::assertSame($account->id, Registration::query()->findOrFail($registration->id)->account_id);

        // The registration, however, is not held down by the constraint.
        DB::table('registrations')->where('id', $registration->id)->delete();
        self::assertNull(Registration::query()->find($registration->id));
    }

    /**
     * Two registrations cannot share a credential, or one credential would
     * resolve two aggregates. It cannot happen by chance; the constraint is
     * what turns "it cannot" into "it did not".
     */
    public function test_a_credential_hash_cannot_be_shared(): void
    {
        $first = $this->started();
        $second = $this->started();

        $this->expectException(QueryException::class);

        DB::table('registrations')
            ->where('id', $second->id)
            ->update(['credential_hash' => $first->credential_hash]);
    }

    /**
     * The row holds proof and nothing else.
     *
     * Every column named here was argued for; a new one arriving without an
     * argument is the failure this catches. A step, a status and anything about
     * a device or a profile are absent on purpose — see the migration.
     *
     * `account_id` is present, and it is the one column that arrived after the
     * table did. It is durable provenance rather than a derived convenience:
     * matching the identifiers against `accounts` answers the question only
     * while a verified identifier cannot change and is never reused, and it is
     * a fact knowable only inside the completion transaction, so no later
     * migration could reconstruct it.
     */
    public function test_the_registration_row_holds_nothing_it_does_not_need(): void
    {
        /** @var list<string> $columns */
        $columns = DB::table('information_schema.columns')
            ->where('table_schema', 'public')
            ->where('table_name', 'registrations')
            ->orderBy('column_name')
            ->pluck('column_name')
            ->all();

        self::assertSame([
            'account_id',
            'completed_at',
            'created_at',
            'credential_hash',
            'email',
            'email_verified_at',
            'expires_at',
            'id',
            'phone_e164',
            'phone_verified_at',
        ], $columns);
    }

    // --------------------------------------------------------------- helpers

    private function started(): Registration
    {
        return Registration::query()->findOrFail($this->registrations->start()->registrationId);
    }

    /**
     * Completion written directly, because what is under test here is what the
     * DATABASE guarantees about the pair — `CompleteRegistration` has its own
     * file for what the transaction does.
     */
    private function completeWith(string $registrationId, string $accountId): void
    {
        DB::table('registrations')->where('id', $registrationId)->update([
            'account_id' => $accountId,
            'completed_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Written directly, because proof attachment does not exist yet. The
     * columns are here so the invariants that govern them can be proven before
     * the slice that writes them arrives.
     */
    private function markProven(string $registrationId, string $column): void
    {
        DB::table('registrations')
            ->where('id', $registrationId)
            ->update([$column => CarbonImmutable::now()]);
    }
}
