<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\ApiError;
use App\Support\ExceptionRenderer;
use Illuminate\Cache\RateLimiter as CacheRateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The coarse per-IP limits, and the store they depend on.
 *
 * No routes use these yet — the contract commit comes first — so the limiters
 * are exercised directly. What matters here is the store: the whole reason for
 * adding two cache tables is that the file store cannot count correctly under
 * concurrency, and that claim deserves an assertion rather than a comment.
 */
final class RateLimitingTest extends TestCase
{
    use RefreshDatabase;

    private function databaseLimiter(): CacheRateLimiter
    {
        return new CacheRateLimiter(Cache::store('database'));
    }

    // ----------------------------------------------------------- definitions

    #[DataProvider('limiters')]
    public function test_each_named_limiter_uses_its_configured_budget(string $name, string $configKey): void
    {
        $limiter = RateLimiter::limiter($name);
        self::assertNotNull($limiter, "the $name limiter should be defined");

        $limit = $limiter(Request::create('/', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']));

        self::assertInstanceOf(Limit::class, $limit);
        self::assertSame((int) config("ridemate.rate_limits.$configKey"), $limit->maxAttempts);
        self::assertSame(3600, $limit->decaySeconds, 'the budgets are stated per hour');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function limiters(): array
    {
        return [
            'otp request' => ['rm-otp-request', 'otp_request_per_ip_per_hour'],
            'otp verify' => ['rm-otp-verify', 'otp_verify_per_ip_per_hour'],
            'refresh' => ['rm-auth-refresh', 'refresh_per_ip_per_hour'],
        ];
    }

    public function test_limits_are_bucketed_per_address(): void
    {
        $limiter = RateLimiter::limiter('rm-otp-request');
        self::assertNotNull($limiter);

        $first = $limiter(Request::create('/', 'POST', server: ['REMOTE_ADDR' => '203.0.113.7']));
        $second = $limiter(Request::create('/', 'POST', server: ['REMOTE_ADDR' => '198.51.100.4']));

        self::assertInstanceOf(Limit::class, $first);
        self::assertInstanceOf(Limit::class, $second);
        self::assertNotSame($first->key, $second->key, 'two addresses must not share a budget');
    }

    /**
     * A request with no resolvable address still lands in a bucket.
     *
     * Sharing one is an inconvenience. Escaping the limit entirely is a hole.
     */
    public function test_a_request_without_an_address_still_gets_a_bucket(): void
    {
        $limiter = RateLimiter::limiter('rm-otp-request');
        self::assertNotNull($limiter);

        $limit = $limiter(Request::create('/', 'POST', server: ['REMOTE_ADDR' => null]));

        self::assertInstanceOf(Limit::class, $limit);
        self::assertNotSame('', $limit->key);
    }

    // ----------------------------------------------------------------- store

    public function test_the_database_store_counts_hits_exactly(): void
    {
        $limiter = $this->databaseLimiter();

        for ($i = 0; $i < 5; $i++) {
            $limiter->hit('rm-test', 3600);
        }

        self::assertSame(5, $limiter->attempts('rm-test'));
        self::assertTrue($limiter->tooManyAttempts('rm-test', 5));
        self::assertFalse($limiter->tooManyAttempts('rm-test', 6));
    }

    public function test_the_limit_releases_when_the_window_passes(): void
    {
        $limiter = $this->databaseLimiter();
        $limiter->hit('rm-test', 60);

        self::assertTrue($limiter->tooManyAttempts('rm-test', 1));

        $this->travel(61)->seconds();

        self::assertFalse($limiter->tooManyAttempts('rm-test', 1));
    }

    /**
     * CARRIES WEIGHT, AND IS THE WHOLE REASON THESE TABLES EXIST.
     *
     * The counting above would pass against the file store too, because
     * sequential increments never contend. The property that actually
     * distinguishes the two stores is that DatabaseStore::increment takes a row
     * lock, so parallel hits serialize instead of overwriting each other.
     *
     * Asserted by inspecting the SQL rather than by racing two processes: a
     * race is nondeterministic in CI and would prove less. If a future Laravel
     * version stopped locking here, the store would silently start losing hits
     * under load — exactly the failure the file store has — and this is what
     * would catch it.
     */
    public function test_the_database_store_locks_the_row_it_increments(): void
    {
        $limiter = $this->databaseLimiter();
        $limiter->hit('rm-test', 3600);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $limiter->hit('rm-test', 3600);

        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $sql = strtolower(implode(' ; ', array_map(
            static fn (array $q): string => (string) $q['query'],
            $queries,
        )));

        self::assertStringContainsString('for update', $sql, 'the increment must be serialized');
    }

    // -------------------------------------------------------------- contract

    /**
     * Being throttled produces the documented envelope, with no new error code.
     *
     * Rendered directly because no route uses these limiters yet. Laravel's
     * ThrottleRequestsException extends TooManyRequestsHttpException, which
     * Phase 8's renderer already maps.
     */
    public function test_throttling_renders_the_documented_error_shape(): void
    {
        $response = ExceptionRenderer::render(
            new ThrottleRequestsException('Too Many Attempts.'),
            Request::create('/api/v1/auth/otp', 'POST'),
        );

        self::assertSame(429, $response->getStatusCode());

        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertIsArray($payload['error']);
        self::assertSame(ApiError::RATE_LIMITED, $payload['error']['code']);
        self::assertArrayHasKey('request_id', $payload['error']);
    }
}
