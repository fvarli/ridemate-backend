<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\RequestId;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\StreamHandler;
use Tests\TestCase;

/**
 * The log is actually structured, end to end.
 *
 * LogRequestTest asserts what the middleware passes to the logger. This goes
 * further and reads the file the configured channel writes, because a JSON
 * formatter that is configured but not wired produces perfectly ordinary text
 * lines and every mock-based test still passes.
 */
final class StructuredLogTest extends TestCase
{
    private string $logFile;

    protected function setUp(): void
    {
        parent::setUp();

        $this->logFile = storage_path('logs/structured-log-test.log');
        @unlink($this->logFile);

        Config::set('logging.channels.test_json', [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'with' => ['stream' => $this->logFile],
            'formatter' => JsonFormatter::class,
            'level' => 'debug',
        ]);
        Config::set('logging.default', 'test_json');
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);

        parent::tearDown();
    }

    public function test_a_request_writes_one_parsable_json_line_carrying_its_id(): void
    {
        $response = $this->getJson('/health');
        $response->assertOk();

        $header = $response->headers->get(RequestId::HEADER);
        self::assertIsString($header);

        $lines = array_filter(explode("\n", (string) file_get_contents($this->logFile)));
        self::assertNotEmpty($lines, 'nothing reached the configured channel');

        $entry = null;
        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            self::assertIsArray($decoded, 'the channel emitted a line that is not JSON');

            if (($decoded['message'] ?? null) === 'http_request') {
                $entry = $decoded;
            }
        }

        self::assertNotNull($entry, 'no http_request line was written');
        self::assertSame($header, $entry['context']['request_id']);
        self::assertSame('GET', $entry['context']['method']);
        self::assertSame('health', $entry['context']['route']);
        self::assertSame(200, $entry['context']['status']);
        self::assertIsInt($entry['context']['duration_ms']);
        self::assertSame('testing', $entry['context']['environment']);
        self::assertArrayHasKey('release', $entry['context']);
    }

    public function test_the_shared_context_reaches_lines_written_by_other_code(): void
    {
        // The middleware shares the id with the logger, so a line written deep
        // in application code carries it without knowing the middleware exists.
        // That is the whole point of correlation.
        $this->get('/health');

        Log::info('written_by_something_else');

        $lines = array_filter(explode("\n", (string) file_get_contents($this->logFile)));
        $found = false;

        foreach ($lines as $line) {
            $decoded = json_decode($line, true);
            if (is_array($decoded) && ($decoded['message'] ?? null) === 'written_by_something_else') {
                self::assertArrayHasKey('request_id', $decoded['context']);
                $found = true;
            }
        }

        self::assertTrue($found);
    }

    public function test_no_request_header_or_body_is_ever_written(): void
    {
        $this->postJson('/api/v1/does-not-exist', [
            'password' => 'super-secret-value',
            'otp' => '123456',
            'phone' => '+905551112233',
        ], [
            'Authorization' => 'Bearer a-token-that-must-not-be-logged',
        ]);

        $contents = (string) file_get_contents($this->logFile);

        foreach ([
            'super-secret-value',
            'a-token-that-must-not-be-logged',
            '123456',
            '+905551112233',
            'Authorization',
            'Bearer',
        ] as $secret) {
            self::assertStringNotContainsString($secret, $contents, "leaked: {$secret}");
        }
    }
}
