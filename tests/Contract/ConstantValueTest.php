<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Osteel\OpenApi\Testing\Exceptions\ValidationException;
use Tests\TestCase;

/**
 * Three values in this contract are fixed, and the fixing has to be real.
 *
 * `Health.status` is always `ok`, `OtpAccepted.status` is always `accepted`,
 * and `TokenPair.token_type` is always `Bearer`. They were written with
 * `const`, which reads correctly and — at the layer that guards the endpoints —
 * did nothing at all.
 *
 * WHY const WAS NOT ENOUGH
 *
 * Two engines validate this document. `assertMatchesSchema` runs
 * opis/json-schema, which implements draft 2020-12 and honours `const`.
 * `assertMatchesOperation` runs league/openapi-psr7-validator, which dispatches
 * an explicit list of twenty-one keywords and silently ignores anything outside
 * it. `const` is outside it.
 *
 * So a response could have carried `"status": "degraded"` past the operation
 * check and only the schema check would have objected — and the schema check is
 * not what an endpoint test runs. A single-value `enum` says the same thing and
 * is implemented by both.
 *
 * These tests exist because the previous state passed everything. Every
 * assertion here is a rejection: proof that a wrong value is now refused where
 * it was previously waved through.
 */
final class ConstantValueTest extends TestCase
{
    use ValidatesTheContract;

    /**
     * A response shaped like a real one.
     *
     * Every response in this contract declares `X-Request-Id` as required, so
     * a synthetic body without it would fail for a reason that has nothing to
     * do with what is under test.
     *
     * @param  array<string, mixed>  $body
     * @return TestResponse<JsonResponse>
     */
    private function response(array $body, int $status): TestResponse
    {
        return TestResponse::fromBaseResponse(new JsonResponse(
            $body,
            $status,
            ['X-Request-Id' => '00000000-0000-7000-8000-0000000000ff'],
        ));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function assertOperationRejects(
        array $body,
        string $path,
        string $method,
        int $status,
        string $why,
    ): void {
        try {
            $this->assertMatchesOperation($this->response($body, $status), $path, $method);
        } catch (ValidationException) {
            // The validator refused it, which is the whole point.
            $this->addToAssertionCount(1);

            return;
        }

        self::fail($why);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function assertOperationAccepts(
        array $body,
        string $path,
        string $method,
        int $status,
    ): void {
        $this->assertMatchesOperation($this->response($body, $status), $path, $method);
    }

    public function test_the_contract_no_longer_relies_on_const(): void
    {
        $document = json_encode(self::contractDocument());
        self::assertIsString($document);

        // Not prose-scanning: `const` is a JSON Schema keyword, so its presence
        // as an object key is exactly the defect. It appears in no description.
        self::assertStringNotContainsString('"const"', $document);
    }

    // ------------------------------------------------------------- health

    public function test_a_health_status_other_than_ok_is_refused_by_the_operation(): void
    {
        $healthy = ['status' => 'ok', 'service' => 'ridemate', 'environment' => 'testing', 'release' => 'dev'];

        $this->assertOperationAccepts($healthy, '/health', 'get', 200);

        $this->assertOperationRejects(
            ['status' => 'degraded'] + $healthy,
            '/health',
            'get',
            200,
            'health may only report ok; anything else is a different contract',
        );
    }

    // -------------------------------------------------------------- otp

    public function test_an_otp_status_other_than_accepted_is_refused_by_the_operation(): void
    {
        $this->assertOperationAccepts(['status' => 'accepted'], '/api/v1/auth/otp', 'post', 202);

        $this->assertOperationRejects(
            ['status' => 'sent'],
            '/api/v1/auth/otp',
            'post',
            202,
            'the passcode endpoint says only that it accepted the request',
        );
    }

    // -------------------------------------------------------- token pair

    /**
     * @return array<string, mixed>
     */
    private function tokenPair(): array
    {
        return [
            'access_token' => 'rma_00000000-0000-7000-8000-000000000001.EXAMPLE',
            'refresh_token' => 'rmr_00000000-0000-7000-8000-000000000002.EXAMPLE',
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'session_id' => '00000000-0000-7000-8000-000000000003',
        ];
    }

    public function test_a_token_type_other_than_bearer_is_refused_by_the_operation(): void
    {
        $this->assertOperationAccepts($this->tokenPair(), '/api/v1/auth/otp/verify', 'post', 200);

        foreach (['bearer', 'Basic', 'Token'] as $wrong) {
            $this->assertOperationRejects(
                ['token_type' => $wrong] + $this->tokenPair(),
                '/api/v1/auth/otp/verify',
                'post',
                200,
                "token_type $wrong must be refused; clients build an Authorization header from it",
            );
        }
    }

    public function test_the_refresh_operation_pins_the_token_type_too(): void
    {
        $this->assertOperationAccepts($this->tokenPair(), '/api/v1/auth/refresh', 'post', 200);

        $this->assertOperationRejects(
            ['token_type' => 'bearer'] + $this->tokenPair(),
            '/api/v1/auth/refresh',
            'post',
            200,
            'refresh returns the same pair shape and the same fixed token type',
        );
    }
}
