<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\OtpChallenge;
use App\Otp\Sms\InMemorySmsSender;
use App\Otp\Sms\SmsSender;
use App\Providers\AppServiceProvider;
use App\Support\ApiError;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * `POST /api/v1/auth/otp` over real HTTP.
 *
 * The heart of this file is the enumeration pair. Everything else confirms the
 * plumbing; that one confirms the property the endpoint exists to have.
 */
final class RequestPasscodeEndpointTest extends TestCase
{
    use CleansCommittedRows;
    use CreatesAccounts;

    /**
     * Truncation, not RefreshDatabase.
     *
     * SendPasscode refuses to run inside a transaction — a caller that wrapped
     * it would turn the issuing commit into a savepoint release and hand out a
     * passcode for a row that could still roll back. RefreshDatabase wraps
     * every test in exactly such a transaction, so it cannot host these tests
     * without disabling the guard they depend on.
     *
     * Tables are named explicitly: `spatial_ref_sys` belongs to PostGIS and
     * emptying it leaves the extension registered and broken.
     */
    use DatabaseTruncation;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'accounts',
        'auth_sessions',
        'auth_tokens',
        'otp_challenges',
    ];

    private const PATH = '/api/v1/auth/otp';

    private const KNOWN = '+905321234567';

    private const UNKNOWN = '+905329876543';

    private InMemorySmsSender $sms;

    protected function setUp(): void
    {
        parent::setUp();

        $this->sms = new InMemorySmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
    }

    protected function tearDown(): void
    {
        // Before parent::tearDown(), which destroys the application.
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    public function test_a_valid_number_is_accepted(): void
    {
        $response = $this->postJson(self::PATH, ['phone' => '0532 123 45 67']);

        $response->assertStatus(202);
        self::assertSame('{"status":"accepted"}', $response->getContent());
        self::assertSame(1, $this->sms->count());
    }

    /**
     * The number is normalized at the boundary, so the row is canonical
     * whatever the member typed.
     */
    public function test_the_number_is_canonicalised_before_anything_is_stored(): void
    {
        $this->postJson(self::PATH, ['phone' => '0532 123 45 67'])->assertStatus(202);

        self::assertSame(self::KNOWN, OtpChallenge::query()->firstOrFail()->phone_e164);
        self::assertSame(self::KNOWN, $this->sms->sent()[0]['phone']);
    }

    #[DataProvider('unusableNumbers')]
    public function test_an_unusable_number_is_a_validation_failure(mixed $phone): void
    {
        $response = $this->postJson(self::PATH, $phone === null ? [] : ['phone' => $phone]);

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ApiError::VALIDATION_FAILED);
        $response->assertJsonStructure(['error' => ['code', 'message', 'details', 'request_id']]);

        self::assertSame(0, OtpChallenge::query()->count(), 'nothing should be issued');
        self::assertSame(0, $this->sms->count());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unusableNumbers(): array
    {
        return [
            'missing' => [null],
            'empty' => [''],
            'letters' => ['not a phone'],
            'too short' => ['12'],
            // Correct country code, correct digit count, a range no carrier
            // owns. A length rule would let this through.
            'well formed but unallocated' => ['+90 999 999 99 99'],
            'not a string' => [12345],
        ];
    }

    // ------------------------------------------------------- enumeration

    /**
     * CARRIES WEIGHT. THIS IS THE ENDPOINT'S REASON FOR ITS SHAPE.
     *
     * A member and a stranger must be indistinguishable. The assertions go
     * beyond status and body to the QUERIES: identical count, identical SQL in
     * identical order, and no reference to `accounts` on either path.
     *
     * Structural, not statistical. There are no sleeps and no timing
     * comparisons here — a timing assertion would be flaky in CI and would
     * prove far less than showing there is no branch to time.
     */
    public function test_a_known_and_an_unknown_number_are_indistinguishable(): void
    {
        $this->createAccount(self::KNOWN);

        $known = $this->captureIssuance(self::KNOWN);
        $unknown = $this->captureIssuance(self::UNKNOWN);

        self::assertSame($known['status'], $unknown['status']);
        self::assertSame(202, $known['status']);

        self::assertSame($known['body'], $unknown['body'], 'the bodies must be byte-identical');
        self::assertSame('{"status":"accepted"}', $known['body']);

        self::assertSame(
            count($known['queries']),
            count($unknown['queries']),
            'the two paths issued a different number of queries',
        );

        self::assertSame(
            $known['queries'],
            $unknown['queries'],
            'the two paths issued a different sequence of statements',
        );

        foreach ([...$known['queries'], ...$unknown['queries']] as $sql) {
            self::assertStringNotContainsStringIgnoringCase(
                'accounts',
                $sql,
                'issuance must never consult accounts',
            );
        }
    }

    /**
     * The response headers must not differ either — a header is as good an
     * oracle as a body.
     */
    public function test_the_response_headers_do_not_differ(): void
    {
        $this->createAccount(self::KNOWN);

        $known = $this->postJson(self::PATH, ['phone' => self::KNOWN]);
        $unknown = $this->postJson(self::PATH, ['phone' => self::UNKNOWN]);

        // X-Request-Id is unique per request by design, so it is compared by
        // presence rather than by value.
        self::assertSame(
            array_keys($known->headers->all()),
            array_keys($unknown->headers->all()),
        );
        self::assertTrue($known->headers->has('X-Request-Id'));
        self::assertTrue($unknown->headers->has('X-Request-Id'));
    }

    /**
     * @return array{status: int, body: string, queries: list<string>}
     */
    private function captureIssuance(string $phone): array
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $response = $this->postJson(self::PATH, ['phone' => $phone]);

        /** @var list<string> $queries */
        $queries = array_map(
            static fn (array $q): string => (string) $q['query'],
            DB::getQueryLog(),
        );
        DB::disableQueryLog();

        return [
            'status' => $response->getStatusCode(),
            'body' => (string) $response->getContent(),
            'queries' => $queries,
        ];
    }

    // ------------------------------------------------------------- limits

    public function test_asking_again_immediately_is_rate_limited(): void
    {
        $this->postJson(self::PATH, ['phone' => self::KNOWN])->assertStatus(202);

        $response = $this->postJson(self::PATH, ['phone' => self::KNOWN]);

        $response->assertStatus(429);
        $response->assertJsonPath('error.code', ApiError::RATE_LIMITED);
        self::assertSame(1, $this->sms->count(), 'no second message should be sent');
    }

    public function test_the_per_address_budget_is_enforced(): void
    {
        config(['ridemate.rate_limits.otp_request_per_ip_per_hour' => 2]);
        $this->refreshApplicationWithLimits();

        // Different numbers each time, so only the per-address budget applies.
        $this->postJson(self::PATH, ['phone' => '+905321111111'])->assertStatus(202);
        $this->postJson(self::PATH, ['phone' => '+905322222222'])->assertStatus(202);

        $this->postJson(self::PATH, ['phone' => '+905323333333'])
            ->assertStatus(429)
            ->assertJsonPath('error.code', ApiError::RATE_LIMITED);
    }

    /**
     * Limiters are registered when the provider boots, so a budget changed
     * mid-test needs them redefined.
     */
    private function refreshApplicationWithLimits(): void
    {
        (new AppServiceProvider($this->app))->boot();
    }

    // --------------------------------------------------- delivery failure

    /**
     * CARRIES WEIGHT. A failed send must not become a cooldown bypass.
     *
     * Delivery failure is something an attacker can provoke. If it reopened
     * the resend window, provoking it repeatedly would be a way to pump
     * messages at a number.
     */
    public function test_a_delivery_failure_is_an_internal_error_that_keeps_the_challenge(): void
    {
        $this->sms->fail();

        $response = $this->postJson(self::PATH, ['phone' => self::KNOWN]);

        $response->assertStatus(500);
        $response->assertJsonPath('error.code', ApiError::INTERNAL_ERROR);

        self::assertSame(1, OtpChallenge::query()->count(), 'the challenge must survive');

        // And the cooldown applies exactly as it would have after a success.
        $this->postJson(self::PATH, ['phone' => self::KNOWN])
            ->assertStatus(429)
            ->assertJsonPath('error.code', ApiError::RATE_LIMITED);
    }

    /**
     * The 500 body must not carry the exception's detail into production.
     */
    public function test_a_delivery_failure_is_sanitised_outside_debug(): void
    {
        config(['app.debug' => false]);
        $this->sms->fail();

        $response = $this->postJson(self::PATH, ['phone' => self::KNOWN]);

        $response->assertStatus(500);
        $response->assertJsonPath('error.message', 'An unexpected error occurred.');
    }
}
