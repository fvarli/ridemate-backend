<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\RequestId;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class RequestIdTest extends TestCase
{
    public function test_every_response_carries_a_request_id(): void
    {
        $header = $this->getJson('/health')->headers->get(RequestId::HEADER);

        self::assertIsString($header);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $header,
        );
    }

    public function test_a_well_formed_inbound_id_is_honoured(): void
    {
        // So a caller can correlate a retry with its original attempt.
        $supplied = '0192f1c9-3b7a-7e21-8b44-2f1d5c8e9a03';

        $response = $this->getJson('/health', [RequestId::HEADER => $supplied]);

        self::assertSame($supplied, $response->headers->get(RequestId::HEADER));
    }

    /**
     * @param  non-empty-string  $hostile
     */
    #[DataProvider('hostileIds')]
    public function test_anything_that_is_not_a_uuid_is_replaced(string $hostile): void
    {
        // The id reaches the log stream, so accepting arbitrary client input
        // would let anyone forge or pollute log lines, and an unbounded value
        // would be a denial of service on log volume.
        $response = $this->getJson('/health', [RequestId::HEADER => $hostile]);

        $header = $response->headers->get(RequestId::HEADER);
        self::assertIsString($header);
        self::assertNotSame($hostile, $header);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function hostileIds(): iterable
    {
        yield 'not a uuid' => ['tail -f /etc/passwd'];
        yield 'newline injection' => ["0192f1c9-3b7a-7e21-8b44-2f1d5c8e9a03\nfake_log_line"];
        yield 'unbounded' => [str_repeat('a', 100_000)];
        yield 'empty' => [' '];
        yield 'nil uuid' => ['00000000-0000-0000-0000-000000000000'];
    }
}
