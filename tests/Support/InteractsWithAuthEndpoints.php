<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Otp\Sms\InMemorySmsSender;
use App\Otp\Sms\SmsSender;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;

/**
 * Drives the authentication endpoints the way a client would.
 *
 * Every helper goes through real HTTP. Seeding a challenge by calling the
 * service directly would be faster and would skip the request validation,
 * middleware and error rendering that these tests exist to cover.
 */
trait InteractsWithAuthEndpoints
{
    protected InMemorySmsSender $sms;

    /**
     * Passcodes are read from the in-memory sender, never from a log or a
     * file — which is what lets the hygiene tests assert they appear in
     * neither.
     */
    protected function bindTestSmsSender(): void
    {
        $this->sms = new InMemorySmsSender;
        $this->app->instance(SmsSender::class, $this->sms);
    }

    /** Requests a passcode over HTTP and returns the code that was sent. */
    protected function requestPasscode(string $phone): string
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => $phone])->assertStatus(202);

        $code = $this->sms->lastCode();
        Assert::assertNotNull($code, 'the sender should have received a passcode');

        return $code;
    }

    /**
     * @param  array<string, string>  $device
     * @return TestResponse<JsonResponse>
     */
    protected function verifyPasscode(string $phone, string $code, array $device = []): TestResponse
    {
        return $this->postJson('/api/v1/auth/otp/verify', ['phone' => $phone, 'code' => $code] + $device);
    }

    /**
     * The whole sign-in flow. Returns the token pair.
     *
     * @return array{access_token: string, refresh_token: string, token_type: string, expires_in: int, session_id: string}
     */
    protected function signIn(string $phone = '+905321234567'): array
    {
        $response = $this->verifyPasscode($phone, $this->requestPasscode($phone));
        $response->assertStatus(200);

        /** @var array{access_token: string, refresh_token: string, token_type: string, expires_in: int, session_id: string} $pair */
        $pair = $response->json();

        return $pair;
    }

    /**
     * @return array<string, string>
     */
    protected function bearer(string $accessToken): array
    {
        return ['Authorization' => 'Bearer '.$accessToken];
    }
}
