<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Auth\AuthContext;
use App\Auth\DeviceDescription;
use App\Auth\TokenService;
use App\Http\Middleware\AuthenticateToken;
use App\Models\AccountStatus;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * The middleware, exercised directly rather than through a route.
 *
 * Deliberate: commit order puts the OpenAPI contract before any endpoint, so
 * there is nothing to send a request to yet. Calling handle() proves the header
 * parsing and the request attribute without inventing a route that the
 * contract does not describe.
 */
final class AuthenticateTokenMiddlewareTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    private function pass(Request $request): SymfonyResponse
    {
        return app(AuthenticateToken::class)->handle(
            $request,
            static fn (): Response => new Response('reached'),
        );
    }

    private function requestWith(?string $header): Request
    {
        $request = Request::create('/anything', 'GET');

        if ($header !== null) {
            $request->headers->set('Authorization', $header);
        }

        return $request;
    }

    public function test_a_valid_credential_reaches_the_route_and_binds_the_context(): void
    {
        $account = $this->createAccount();
        $pair = app(TokenService::class)->issue($account, DeviceDescription::unknown());

        $request = $this->requestWith('Bearer '.$pair->accessToken);
        $response = $this->pass($request);

        self::assertSame('reached', $response->getContent());

        $context = AuthContext::tryOf($request);
        self::assertInstanceOf(AuthContext::class, $context);
        self::assertSame($account->id, $context->account->id);
        self::assertSame($pair->sessionId, $context->session->id);
    }

    #[DataProvider('rejectedHeaders')]
    public function test_a_missing_or_malformed_header_is_refused(?string $header): void
    {
        $this->expectException(AuthenticationException::class);
        $this->pass($this->requestWith($header));
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function rejectedHeaders(): array
    {
        return [
            'absent' => [null],
            'empty' => [''],
            'no scheme' => ['rma_0198a1b2-c3d4-7000-8000-000000000000.secret'],
            'wrong scheme' => ['Basic dXNlcjpwYXNz'],
            'lowercase scheme' => ['bearer rma_0198a1b2-c3d4-7000-8000-000000000000.secret'],
            'scheme only' => ['Bearer '],
        ];
    }

    public function test_a_suspended_account_is_refused_as_forbidden(): void
    {
        $account = $this->createAccount();
        $pair = app(TokenService::class)->issue($account, DeviceDescription::unknown());

        $account->status = AccountStatus::Suspended;
        $account->save();

        $this->expectException(AuthorizationException::class);
        $this->pass($this->requestWith('Bearer '.$pair->accessToken));
    }

    /**
     * The credential must not survive on the request for something downstream
     * to log. Only the resolved context does.
     */
    public function test_the_credential_is_not_copied_onto_the_request(): void
    {
        $pair = app(TokenService::class)->issue($this->createAccount(), DeviceDescription::unknown());

        $request = $this->requestWith('Bearer '.$pair->accessToken);
        $this->pass($request);

        $attributes = $request->attributes->all();

        self::assertArrayHasKey(AuthContext::ATTRIBUTE, $attributes);

        // The whole graph, not just string values: the context carries three
        // models, and a future change that stashed the raw credential on any
        // of them would be invisible to a shallow check.
        self::assertStringNotContainsString($pair->accessToken, print_r($attributes, true));
        self::assertStringNotContainsString($pair->refreshToken, print_r($attributes, true));
    }
}
