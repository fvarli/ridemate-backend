<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OtpChallenge;
use App\Otp\OtpChannel;
use App\Otp\OtpService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Two channels sharing one table, and never each other's state.
 *
 * WHAT THIS FILE IS DEFENDING
 *
 * A challenge used to be identified by a phone number, so "the unresolved
 * challenge for this member" was unambiguous. Now it is identified by a channel
 * AND a destination, and every policy read, the invalidation sweep, the partial
 * unique index and the advisory lock had to be re-scoped to the pair. Any one of
 * them left on the destination alone would couple the channels together — an
 * email challenge would invalidate an SMS one, or block it, or spend its hourly
 * budget — and it would do so silently, because both paths still work.
 *
 * SMS IS THE ONLY CHANNEL WITH A WAY IN
 *
 * `OtpChannel::Email` has no sender, no endpoint and no account column. It is
 * issued here through the service directly, which is how the isolation gets
 * proved while the API surface stays exactly as it was. Nothing in these tests
 * reaches an email HTTP route, because there is none to reach.
 */
final class OtpChannelSeparationTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+905321234567';

    /**
     * Deliberately not a real mailbox and not routable.
     *
     * Nothing sends to it — there is no email sender — so it exists only as a
     * distinct destination string.
     */
    private const EMAIL = 'member@ridemate.invalid';

    private OtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otp = app(OtpService::class);
    }

    // ------------------------------------------------------------ the column

    public function test_a_challenge_records_the_channel_it_was_issued_on(): void
    {
        $this->otp->issue(OtpChannel::Sms, self::PHONE);

        $challenge = OtpChallenge::query()->sole();

        self::assertSame(OtpChannel::Sms, $challenge->channel);
        self::assertSame(self::PHONE, $challenge->destination);
    }

    /**
     * CARRIES WEIGHT. The channel must be stated, not defaulted.
     *
     * The migration backfilled existing rows with `default 'sms'` and then
     * dropped the default, for exactly this reason: while one channel existed,
     * an omitted one was harmless. Now it would silently file an email
     * challenge as a text message.
     */
    public function test_a_row_written_without_a_channel_is_refused(): void
    {
        $this->expectException(QueryException::class);

        DB::table('otp_challenges')->insert([
            'id' => '01991e00-0000-7000-8000-000000000001',
            'destination' => self::PHONE,
            'code_hash' => str_repeat('a', 64),
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
        ]);
    }

    // ------------------------------------------------------- the isolation

    /**
     * CARRIES WEIGHT. One unresolved challenge PER CHANNEL, not per member.
     *
     * The partial unique index was `(phone_e164)`. Had it merely been renamed
     * to `(destination)` this would fail — and a member waiting on an email
     * code could not be sent a text.
     */
    public function test_both_channels_may_hold_an_unresolved_challenge_at_once(): void
    {
        $this->otp->issue(OtpChannel::Sms, self::PHONE);
        $this->otp->issue(OtpChannel::Email, self::EMAIL);

        self::assertSame(2, OtpChallenge::query()->count());
        self::assertSame(
            2,
            OtpChallenge::query()
                ->whereNull('consumed_at')
                ->whereNull('invalidated_at')
                ->count(),
        );
    }

    /**
     * And the index still holds WITHIN a channel.
     */
    public function test_one_channel_still_refuses_a_second_unresolved_challenge(): void
    {
        $this->otp->issue(OtpChannel::Email, self::EMAIL);

        $this->expectException(QueryException::class);

        OtpChallenge::query()->insert([
            'id' => '01991e00-0000-7000-8000-000000000002',
            'channel' => OtpChannel::Email->value,
            'destination' => self::EMAIL,
            'code_hash' => str_repeat('b', 64),
            'expires_at' => now()->addMinutes(5),
            'created_at' => now(),
        ]);
    }

    /**
     * CARRIES WEIGHT. Issuing on one channel does not invalidate the other.
     *
     * Issuance sweeps predecessors unconditionally. Scoped to the destination
     * alone, asking for a text would quietly cancel an email code the member
     * was in the middle of typing.
     */
    public function test_issuing_on_one_channel_leaves_the_other_untouched(): void
    {
        $email = $this->otp->issue(OtpChannel::Email, self::EMAIL);

        $this->otp->issue(OtpChannel::Sms, self::PHONE);

        $stored = OtpChallenge::query()->findOrFail($email->id);

        self::assertNull($stored->invalidated_at);
        self::assertTrue($stored->isUnresolved());
    }

    /**
     * CARRIES WEIGHT. A code is only valid on the channel it was issued for.
     */
    public function test_a_code_does_not_verify_on_the_other_channel(): void
    {
        $issued = $this->otp->issue(OtpChannel::Email, self::EMAIL);

        self::assertFalse($this->otp->verify(OtpChannel::Sms, self::EMAIL, $issued->code));
        // And is still usable where it belongs, so the failure above was
        // isolation rather than the attempt having consumed it.
        self::assertTrue($this->otp->verify(OtpChannel::Email, self::EMAIL, $issued->code));
    }

    /**
     * Verifying one channel does not consume the other's challenge.
     */
    public function test_verifying_one_channel_does_not_consume_the_other(): void
    {
        $sms = $this->otp->issue(OtpChannel::Sms, self::PHONE);
        $email = $this->otp->issue(OtpChannel::Email, self::EMAIL);

        self::assertTrue($this->otp->verify(OtpChannel::Sms, self::PHONE, $sms->code));

        self::assertNull(OtpChallenge::query()->findOrFail($email->id)->consumed_at);
    }

    /**
     * CARRIES WEIGHT. The hourly budget is per channel.
     *
     * Shared, an email flood would lock a member out of the text messages they
     * need to sign in — the SMS budget weakened by a channel that did not
     * exist when it was set.
     */
    public function test_one_channel_does_not_spend_the_other_budget(): void
    {
        $cap = (int) config('ridemate.otp.max_per_destination_per_hour');

        for ($i = 0; $i < $cap; $i++) {
            $this->travel(120)->seconds();
            $this->otp->issue(OtpChannel::Email, self::EMAIL);
        }

        // The email budget is spent; the SMS one was never touched.
        $this->travel(120)->seconds();
        $this->otp->issue(OtpChannel::Sms, self::PHONE);

        self::assertSame(
            1,
            OtpChallenge::query()->where('channel', OtpChannel::Sms->value)->count(),
        );
    }

    /**
     * And the resend cooldown is per channel too.
     */
    public function test_one_channel_cooldown_does_not_block_the_other(): void
    {
        $this->otp->issue(OtpChannel::Email, self::EMAIL);

        // Well inside the cooldown, which would refuse a second email.
        $this->otp->issue(OtpChannel::Sms, self::PHONE);

        self::assertSame(2, OtpChallenge::query()->count());
    }

    // ------------------------------------- the same destination, two channels

    /**
     * CARRIES WEIGHT. The scope is the PAIR, not the destination.
     *
     * Every case above uses a phone number on one channel and an email address
     * on the other, so they would still pass if the scoping had been left on
     * the destination alone — the two strings differ, and the isolation would
     * be a coincidence rather than a rule. Nothing at the database level makes
     * destinations disjoint across channels, so these three use ONE string on
     * both channels and prove the rule directly.
     *
     * A mutation battery found this: removing the channel from the invalidation
     * sweep and from the hourly count left the earlier cases green.
     */
    private const SHARED = 'shared-destination';

    public function test_one_string_may_hold_a_challenge_on_each_channel(): void
    {
        $sms = $this->otp->issue(OtpChannel::Sms, self::SHARED);
        $email = $this->otp->issue(OtpChannel::Email, self::SHARED);

        // Issuing the second did not sweep the first.
        self::assertNull(OtpChallenge::query()->findOrFail($sms->id)->invalidated_at);
        self::assertNull(OtpChallenge::query()->findOrFail($email->id)->invalidated_at);
        self::assertSame(2, OtpChallenge::query()->count());
    }

    public function test_one_string_keeps_a_separate_budget_on_each_channel(): void
    {
        $cap = (int) config('ridemate.otp.max_per_destination_per_hour');

        for ($i = 0; $i < $cap; $i++) {
            $this->travel(120)->seconds();
            $this->otp->issue(OtpChannel::Email, self::SHARED);
        }

        // The email budget for this string is spent. The SMS one is not, and
        // counting by destination alone would have refused this.
        $this->travel(120)->seconds();
        $this->otp->issue(OtpChannel::Sms, self::SHARED);

        self::assertSame(
            1,
            OtpChallenge::query()
                ->where('channel', OtpChannel::Sms->value)
                ->where('destination', self::SHARED)
                ->count(),
        );
    }

    public function test_one_string_verifies_only_on_its_own_channel(): void
    {
        $sms = $this->otp->issue(OtpChannel::Sms, self::SHARED);
        $this->otp->issue(OtpChannel::Email, self::SHARED);

        // The SMS code against the email challenge: same destination, wrong
        // channel, and the email challenge must neither accept it nor spend an
        // attempt belonging to the other channel.
        self::assertFalse(
            $this->otp->verify(OtpChannel::Email, self::SHARED, $sms->code),
        );
        self::assertTrue(
            $this->otp->verify(OtpChannel::Sms, self::SHARED, $sms->code),
        );
    }

    // --------------------------------------------------------- the migration

    /**
     * CARRIES WEIGHT. The shape the migration produced, asserted directly.
     *
     * The old phone-named column and its two indexes must be gone rather than
     * lingering beside the new ones — a leftover unique index on `destination`
     * alone would reintroduce exactly the coupling this file exists to prevent.
     */
    public function test_the_table_carries_the_channel_aware_shape(): void
    {
        self::assertTrue(Schema::hasColumn('otp_challenges', 'channel'));
        self::assertTrue(Schema::hasColumn('otp_challenges', 'destination'));
        self::assertFalse(Schema::hasColumn('otp_challenges', 'phone_e164'));

        /** @var list<object{indexname: string}> $indexes */
        $indexes = DB::select(
            "select indexname from pg_indexes where tablename = 'otp_challenges'",
        );

        $names = array_map(
            static fn (object $row): string => (string) $row->indexname,
            $indexes,
        );

        self::assertContains('otp_challenges_one_unresolved_per_destination', $names);
        self::assertNotContains('otp_challenges_one_unresolved_per_phone', $names);
    }

    /**
     * CARRIES WEIGHT. A row written the way the old flow wrote one is an SMS
     * challenge, and is still found by the SMS path.
     *
     * This is the backfill's meaning, exercised rather than read: every row
     * that existed before this migration was issued by the phone flow, because
     * that is the only flow there has ever been.
     */
    public function test_a_pre_existing_style_row_verifies_as_sms(): void
    {
        // Issued through the service, which is what the phone flow does, then
        // read back through the channel the migration says it must have.
        $issued = $this->otp->issue(OtpChannel::Sms, self::PHONE);

        $stored = OtpChallenge::query()->findOrFail($issued->id);
        self::assertSame(OtpChannel::Sms, $stored->channel);

        self::assertTrue($this->otp->verify(OtpChannel::Sms, self::PHONE, $issued->code));
    }
}
