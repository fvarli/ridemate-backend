<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

final class LogRequestTest extends TestCase
{
    /**
     * The sensitive-data policy, enforced.
     *
     * Bodies, headers, query strings and raw URIs are not logged at all —
     * not redacted. A redaction denylist is wrong by default the first time
     * someone adds a sensitive field and forgets to update it; an allowlist
     * of scalars that cannot carry a secret fails safe instead. Widening this
     * set is a deliberate act with a failing test in the way.
     */
    public function test_the_request_log_line_carries_an_exact_allowlist(): void
    {
        $captured = [];

        Log::shouldReceive('shareContext')->andReturnNull();
        Log::shouldReceive('info')
            ->once()
            ->with('http_request', \Mockery::on(function (array $context) use (&$captured): bool {
                $captured = $context;

                return true;
            }));

        $this->getJson('/health')->assertOk();

        self::assertSame(
            ['request_id', 'method', 'route', 'status', 'duration_ms', 'environment', 'release'],
            array_keys($captured),
        );
    }

    public function test_the_matched_route_is_logged_rather_than_the_raw_uri(): void
    {
        // A raw URI can contain an identifier, a one-time token or a phone
        // number in a path segment. The route pattern cannot.
        $captured = [];

        Log::shouldReceive('shareContext')->andReturnNull();
        Log::shouldReceive('info')
            ->once()
            ->with('http_request', \Mockery::on(function (array $context) use (&$captured): bool {
                $captured = $context;

                return true;
            }));

        $this->getJson('/health');

        self::assertSame('health', $captured['route']);
    }

    public function test_the_logger_is_the_structured_json_channel(): void
    {
        self::assertSame('json', config('logging.default'));
        self::assertInstanceOf(LoggerInterface::class, Log::getFacadeRoot());
    }
}
