<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * Nothing secret reaches the log. Proven against the FILE, not the event.
 *
 * WHY THIS WRITES AND READS A REAL LOG FILE
 *
 * Inspecting MessageLogged events is not enough. An exception is passed to the
 * logger as an OBJECT, and what ends up on disk depends on the formatter —
 * which may serialise the stack trace. PHP's traces include scalar arguments,
 * so a throw from inside `sendPasscode($phone, $code)` can put both of them in
 * a log line without any code ever having logged them.
 *
 * That is a leak nobody would find by reading the application, so the test
 * exercises the whole pipeline and greps the output.
 *
 * Requests here are deliberately real HTTP, including malformed ones, so
 * Laravel's own validation and exception-reporting paths are covered rather
 * than only the paths this codebase wrote.
 */
final class AuthLogHygieneTest extends TestCase
{
    use CleansCommittedRows;
    use CreatesAccounts;
    use DatabaseTruncation;
    use InteractsWithAuthEndpoints;

    /** @var list<string> */
    protected array $tablesToTruncate = ['accounts', 'auth_sessions', 'auth_tokens', 'otp_challenges'];

    private const PHONE = '+905321234567';

    private string $logPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->bindTestSmsSender();

        $this->logPath = storage_path('logs/hygiene-'.bin2hex(random_bytes(6)).'.log');

        config([
            'logging.default' => 'hygiene',
            'logging.channels.hygiene' => [
                'driver' => 'single',
                'path' => $this->logPath,
                'level' => 'debug',
            ],
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->logPath)) {
            unlink($this->logPath);
        }

        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    private function logContents(): string
    {
        return is_file($this->logPath) ? (string) file_get_contents($this->logPath) : '';
    }

    /**
     * The whole flow, then the whole log.
     */
    public function test_no_passcode_or_credential_reaches_the_log(): void
    {
        $code = $this->requestPasscode(self::PHONE);

        /** @var array<string, string> $pair */
        $pair = $this->verifyPasscode(self::PHONE, $code)->assertStatus(200)->json();

        /** @var array<string, string> $rotated */
        $rotated = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']])
            ->assertStatus(200)->json();

        $this->postJson('/api/v1/auth/logout', [], $this->bearer($rotated['access_token']))
            ->assertStatus(204);

        $log = $this->logContents();

        self::assertNotSame('', $log, 'the request logger should have written something');

        foreach ([
            'passcode' => $code,
            'access token' => $pair['access_token'],
            'refresh token' => $pair['refresh_token'],
            'rotated access token' => $rotated['access_token'],
            'rotated refresh token' => $rotated['refresh_token'],
            'phone number' => self::PHONE,
        ] as $label => $secret) {
            self::assertStringNotContainsString($secret, $log, "the {$label} reached the log");
        }
    }

    /**
     * CARRIES WEIGHT. The 500 path is the dangerous one.
     *
     * A delivery failure throws from a method whose arguments are the phone
     * number and the passcode, and an unhandled throwable is reported with its
     * trace.
     */
    public function test_a_delivery_failure_does_not_log_what_it_was_delivering(): void
    {
        $this->sms->fail();

        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertStatus(500);

        $log = $this->logContents();

        self::assertStringContainsString('otp.delivery_failed', $log, 'the failure should be recorded');

        // Non-vacuity: the exception really is reported here, with a trace.
        // Without this, the assertions below could pass simply because nothing
        // was written at all.
        self::assertStringContainsString('SmsDeliveryFailed', $log);
        self::assertStringContainsString('[stacktrace]', $log);

        // THE ACTUAL PROPERTY. The throwing frame is
        // `sendPasscode($phone, $code)`, so a trace rendered WITH arguments
        // would put both in the log without any code having logged them.
        // Laravel renders frames argument-free; this pins that, because
        // enabling stack-trace arguments is a formatter setting away.
        self::assertStringContainsString('sendPasscode()', $log, 'the frame should be present');
        self::assertDoesNotMatchRegularExpression(
            '/sendPasscode\([^)]/',
            $log,
            'the stack trace is rendering call arguments, which are the number and the passcode',
        );

        self::assertStringNotContainsString(self::PHONE, $log, 'the number reached the log');
        self::assertStringNotContainsString('905321234567', $log);
        self::assertDoesNotMatchRegularExpression(
            '/\b\d{6}\b/',
            $log,
            'something six digits long reached the log, which a passcode is',
        );
    }

    /**
     * Malformed requests go through Laravel's own validation and reporting,
     * which this codebase does not own.
     */
    public function test_malformed_requests_do_not_echo_their_input_into_the_log(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE.'CANARY']);
        $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => 'CANARY']);
        $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'rmr_CANARY']);
        $this->postJson('/api/v1/auth/logout', [], ['Authorization' => 'Bearer rma_CANARY']);

        self::assertStringNotContainsString('CANARY', $this->logContents());
    }

    /**
     * The error body is developer-facing English and must never contain
     * anything the caller sent.
     */
    public function test_error_bodies_do_not_echo_credentials(): void
    {
        $canary = 'rmr_00000000-0000-7000-8000-000000000000.CANARYSECRET';

        $body = (string) $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $canary])
            ->assertStatus(401)
            ->getContent();

        self::assertStringNotContainsString('CANARYSECRET', $body);
        self::assertStringNotContainsString($canary, $body);
    }

    /**
     * request_id stays present and safe on every error, which is what makes a
     * member's screenshot enough to find the log line.
     */
    public function test_the_request_id_is_present_and_carries_nothing_else(): void
    {
        $response = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => 'rmr_bad']);

        $response->assertStatus(401);

        $requestId = $response->json('error.request_id');
        self::assertIsString($requestId);
        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            $requestId,
        );
        self::assertSame($requestId, $response->headers->get('X-Request-Id'));
    }
}
