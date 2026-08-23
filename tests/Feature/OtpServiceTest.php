<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\OtpChallenge;
use App\Otp\OtpService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Tests\TestCase;

/**
 * Passcode issuance and verification.
 *
 * The interesting assertions are the ones about state that should NOT block a
 * member (expired rows) and state that MUST block them (cooldown, caps,
 * single use). Getting either backwards is a lockout or a hole, and neither
 * shows up in a happy-path test.
 */
final class OtpServiceTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = '+905321234567';

    private const OTHER = '+905329876543';

    private OtpService $otp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->otp = app(OtpService::class);
    }

    // --------------------------------------------------------------- issuing

    public function test_issuing_writes_a_challenge_and_returns_its_passcode(): void
    {
        $issued = $this->otp->issue(self::PHONE);

        self::assertTrue(Str::isUuid($issued->id));
        self::assertSame(self::PHONE, $issued->phoneE164);
        self::assertMatchesRegularExpression('/^\d{6}$/', $issued->code);

        $challenge = OtpChallenge::query()->findOrFail($issued->id);
        self::assertSame(self::PHONE, $challenge->phone_e164);
        self::assertSame(0, $challenge->attempts);
        self::assertTrue($challenge->isUnresolved());
        self::assertTrue($challenge->expires_at->isFuture());
    }

    public function test_the_passcode_length_comes_from_configuration(): void
    {
        config(['ridemate.otp.length' => 4]);

        self::assertMatchesRegularExpression('/^\d{4}$/', $this->otp->issue(self::PHONE)->code);
    }

    /**
     * CARRIES WEIGHT. The plaintext is nowhere in the row.
     *
     * Scans every column, so a future change that stored the code "just for
     * debugging" fails here rather than in a breach.
     */
    public function test_the_passcode_is_never_stored(): void
    {
        $issued = $this->otp->issue(self::PHONE);

        foreach ((array) DB::table('otp_challenges')->first() as $value) {
            if (is_string($value)) {
                self::assertStringNotContainsString($issued->code, $value);
            }
        }
    }

    /**
     * CARRIES WEIGHT. Issuance cannot leak whether an account exists.
     *
     * Asserted structurally rather than by timing: the service never queries
     * `accounts` at all, so there is no branch that could differ. A timing
     * assertion would be noisy in CI and would prove far less.
     */
    public function test_issuing_never_queries_the_accounts_table(): void
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $this->otp->issue(self::PHONE);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        self::assertNotEmpty($queries);

        foreach ($queries as $query) {
            self::assertStringNotContainsStringIgnoringCase(
                'accounts',
                (string) $query['query'],
                'issuance must not touch accounts, or it could reveal who has one',
            );
        }
    }

    public function test_a_new_passcode_invalidates_its_predecessor(): void
    {
        $first = $this->otp->issue(self::PHONE);

        $this->travel(config('ridemate.otp.resend_cooldown') + 1)->seconds();
        $second = $this->otp->issue(self::PHONE);

        self::assertNotNull(OtpChallenge::query()->findOrFail($first->id)->invalidated_at);
        self::assertTrue(OtpChallenge::query()->findOrFail($second->id)->isUnresolved());
    }

    /**
     * CARRIES WEIGHT, AND IS THE LOCKOUT THIS DESIGN HAD TO AVOID.
     *
     * The partial unique index cannot mention expiry, because `now()` is not
     * IMMUTABLE. So an expired challenge stays "unresolved" until something
     * clears it, and if issuance did not invalidate predecessors
     * unconditionally, a member whose code timed out could never obtain
     * another one until a pruning job happened to run.
     */
    public function test_an_expired_but_unpruned_challenge_does_not_block_a_new_one(): void
    {
        $first = $this->otp->issue(self::PHONE);

        // Well past expiry, and nothing has pruned the row.
        $this->travel(config('ridemate.otp.ttl') + 3600)->seconds();
        self::assertTrue(OtpChallenge::query()->findOrFail($first->id)->isUnresolved());
        self::assertTrue(OtpChallenge::query()->findOrFail($first->id)->expires_at->isPast());

        $second = $this->otp->issue(self::PHONE);

        self::assertNotSame($first->id, $second->id);
        self::assertTrue($this->otp->verify(self::PHONE, $second->code));
    }

    /**
     * The database invariant, exercised directly rather than through the
     * service — because the service is precisely what stops callers meeting it.
     */
    public function test_the_index_refuses_a_second_unresolved_challenge(): void
    {
        $this->otp->issue(self::PHONE);

        $this->expectException(QueryException::class);

        DB::table('otp_challenges')->insert([
            'id' => Str::uuid7()->toString(),
            'phone_e164' => self::PHONE,
            'code_hash' => str_repeat('a', 64),
            'expires_at' => CarbonImmutable::now()->addMinutes(5),
            'attempts' => 0,
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    // ---------------------------------------------------------------- policy

    public function test_a_second_passcode_within_the_cooldown_is_refused(): void
    {
        $this->otp->issue(self::PHONE);

        $this->expectException(TooManyRequestsHttpException::class);
        $this->otp->issue(self::PHONE);
    }

    public function test_the_cooldown_releases(): void
    {
        $this->otp->issue(self::PHONE);
        $this->travel(config('ridemate.otp.resend_cooldown') + 1)->seconds();

        $this->otp->issue(self::PHONE);

        self::assertSame(2, OtpChallenge::query()->where('phone_e164', self::PHONE)->count());
    }

    public function test_the_hourly_cap_is_enforced(): void
    {
        $cooldown = (int) config('ridemate.otp.resend_cooldown');
        $cap = (int) config('ridemate.otp.max_per_phone_per_hour');

        for ($i = 0; $i < $cap; $i++) {
            $this->otp->issue(self::PHONE);
            $this->travel($cooldown + 1)->seconds();
        }

        $this->expectException(TooManyRequestsHttpException::class);
        $this->otp->issue(self::PHONE);
    }

    public function test_the_hourly_cap_is_per_number(): void
    {
        $cooldown = (int) config('ridemate.otp.resend_cooldown');
        $cap = (int) config('ridemate.otp.max_per_phone_per_hour');

        for ($i = 0; $i < $cap; $i++) {
            $this->otp->issue(self::PHONE);
            $this->travel($cooldown + 1)->seconds();
        }

        // A different member must be unaffected by this one's history.
        $this->otp->issue(self::OTHER);

        self::assertSame(1, OtpChallenge::query()->where('phone_e164', self::OTHER)->count());
    }

    // ---------------------------------------------------------- verification

    public function test_the_right_passcode_verifies_and_is_consumed(): void
    {
        $issued = $this->otp->issue(self::PHONE);

        self::assertTrue($this->otp->verify(self::PHONE, $issued->code));
        self::assertNotNull(OtpChallenge::query()->findOrFail($issued->id)->consumed_at);
    }

    public function test_a_passcode_works_exactly_once(): void
    {
        $issued = $this->otp->issue(self::PHONE);

        self::assertTrue($this->otp->verify(self::PHONE, $issued->code));
        self::assertFalse($this->otp->verify(self::PHONE, $issued->code));
    }

    public function test_a_wrong_passcode_fails_and_costs_an_attempt(): void
    {
        $issued = $this->otp->issue(self::PHONE);

        self::assertFalse($this->otp->verify(self::PHONE, $this->wrongCodeFor($issued->code)));
        self::assertSame(1, OtpChallenge::query()->findOrFail($issued->id)->attempts);
    }

    /**
     * CARRIES WEIGHT. Exactly five guesses, then nothing.
     *
     * Off by one in either direction matters: six attempts is a weaker
     * credential than the design says, and four locks a member out early.
     */
    public function test_the_attempt_cap_is_exact(): void
    {
        $max = (int) config('ridemate.otp.max_attempts');
        $issued = $this->otp->issue(self::PHONE);
        $wrong = $this->wrongCodeFor($issued->code);

        for ($i = 0; $i < $max; $i++) {
            self::assertFalse($this->otp->verify(self::PHONE, $wrong), "attempt $i");
        }

        self::assertSame($max, OtpChallenge::query()->findOrFail($issued->id)->attempts);

        // Exhausted: even the correct passcode is refused, and the counter
        // stops rather than climbing forever.
        self::assertFalse($this->otp->verify(self::PHONE, $issued->code));
        self::assertSame($max, OtpChallenge::query()->findOrFail($issued->id)->attempts);
    }

    public function test_an_expired_passcode_does_not_verify(): void
    {
        $issued = $this->otp->issue(self::PHONE);

        $this->travel(config('ridemate.otp.ttl') + 1)->seconds();

        self::assertFalse($this->otp->verify(self::PHONE, $issued->code));
    }

    public function test_an_invalidated_passcode_does_not_verify(): void
    {
        $first = $this->otp->issue(self::PHONE);

        $this->travel(config('ridemate.otp.resend_cooldown') + 1)->seconds();
        $this->otp->issue(self::PHONE);

        self::assertFalse($this->otp->verify(self::PHONE, $first->code));
    }

    public function test_verifying_a_number_with_no_challenge_fails(): void
    {
        self::assertFalse($this->otp->verify(self::PHONE, '123456'));
    }

    /**
     * CARRIES WEIGHT. The hash is bound to the number.
     *
     * Without binding, a passcode issued to one member would verify for
     * another who happened to receive the same six digits — a one-in-a-million
     * coincidence that an attacker can farm.
     */
    public function test_a_passcode_issued_to_one_number_does_not_verify_for_another(): void
    {
        $mine = $this->otp->issue(self::PHONE);
        $theirs = $this->otp->issue(self::OTHER);

        self::assertFalse($this->otp->verify(self::OTHER, $mine->code));
        self::assertTrue($this->otp->verify(self::OTHER, $theirs->code));
    }

    private function wrongCodeFor(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }
}
