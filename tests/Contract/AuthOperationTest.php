<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Models\AccountStatus;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * Every response these endpoints actually produce, validated against the spec.
 *
 * AuthContractTest checked the document and its schemas before any endpoint
 * existed. This is the other half: real responses from real requests, held to
 * the operation the document describes for that path, method and status.
 *
 * Together they close the loop. The first says the contract is coherent; this
 * says the service obeys it. Drift becomes a failing build rather than
 * something a client discovers.
 *
 * All five documented paths are now served, so every one of them has entries
 * here.
 */
final class AuthOperationTest extends TestCase
{
    use CleansCommittedRows;
    use CreatesAccounts;
    use DatabaseTruncation;
    use InteractsWithAuthEndpoints;
    use ValidatesTheContract;

    /** @var list<string> */
    protected array $tablesToTruncate = ['accounts', 'auth_sessions', 'auth_tokens', 'otp_challenges'];

    private const PHONE = '+905321234567';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTestSmsSender();
    }

    protected function tearDown(): void
    {
        // Before parent::tearDown(), which destroys the application.
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    // ------------------------------------------------------ request passcode

    public function test_the_accepted_response_matches_the_contract(): void
    {
        $response = $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);

        $response->assertStatus(202);
        $this->assertMatchesOperation($response, '/api/v1/auth/otp', 'post');
    }

    public function test_the_passcode_validation_failure_matches_the_contract(): void
    {
        $response = $this->postJson('/api/v1/auth/otp', ['phone' => 'nope']);

        $response->assertStatus(422);
        $this->assertMatchesOperation($response, '/api/v1/auth/otp', 'post');
    }

    public function test_the_passcode_rate_limit_matches_the_contract(): void
    {
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertStatus(202);
        $response = $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);

        $response->assertStatus(429);
        $this->assertMatchesOperation($response, '/api/v1/auth/otp', 'post');
    }

    public function test_the_delivery_failure_matches_the_contract(): void
    {
        $this->sms->fail();
        $response = $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE]);

        $response->assertStatus(500);
        $this->assertMatchesOperation($response, '/api/v1/auth/otp', 'post');
    }

    // ------------------------------------------------------- verify passcode

    public function test_the_token_pair_matches_the_contract(): void
    {
        $code = $this->requestPasscode(self::PHONE);
        $response = $this->verifyPasscode(self::PHONE, $code, [
            'device_name' => 'Pixel 8',
            'platform' => 'android',
            'app_version' => '1.0.0',
        ]);

        $response->assertStatus(200);
        $this->assertMatchesOperation($response, '/api/v1/auth/otp/verify', 'post');
    }

    public function test_a_rejected_passcode_matches_the_contract(): void
    {
        $code = $this->requestPasscode(self::PHONE);
        $response = $this->verifyPasscode(self::PHONE, $code === '000000' ? '111111' : '000000');

        $response->assertStatus(401);
        $this->assertMatchesOperation($response, '/api/v1/auth/otp/verify', 'post');
    }

    public function test_a_suspended_account_matches_the_contract(): void
    {
        $this->createAccount(self::PHONE, AccountStatus::Suspended);
        $code = $this->requestPasscode(self::PHONE);

        $response = $this->verifyPasscode(self::PHONE, $code);

        $response->assertStatus(403);
        $this->assertMatchesOperation($response, '/api/v1/auth/otp/verify', 'post');
    }

    public function test_a_malformed_verification_matches_the_contract(): void
    {
        $response = $this->postJson('/api/v1/auth/otp/verify', ['phone' => self::PHONE, 'code' => 'abc']);

        $response->assertStatus(422);
        $this->assertMatchesOperation($response, '/api/v1/auth/otp/verify', 'post');
    }

    // -------------------------------------------------------------- refresh

    public function test_a_rotated_pair_matches_the_contract(): void
    {
        $pair = $this->signIn(self::PHONE);

        $response = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']]);

        $response->assertStatus(200);
        $this->assertMatchesOperation($response, '/api/v1/auth/refresh', 'post');
    }

    public function test_a_refused_refresh_matches_the_contract(): void
    {
        $response = $this->postJson('/api/v1/auth/refresh', [
            'refresh_token' => 'rmr_00000000-0000-7000-8000-000000000000.nope',
        ]);

        $response->assertStatus(401);
        $this->assertMatchesOperation($response, '/api/v1/auth/refresh', 'post');
    }

    public function test_a_suspended_refresh_matches_the_contract(): void
    {
        $account = $this->createAccount(self::PHONE);
        $pair = $this->signIn(self::PHONE);

        $account->status = AccountStatus::Suspended;
        $account->save();

        $response = $this->postJson('/api/v1/auth/refresh', ['refresh_token' => $pair['refresh_token']]);

        $response->assertStatus(403);
        $this->assertMatchesOperation($response, '/api/v1/auth/refresh', 'post');
    }

    public function test_a_malformed_refresh_matches_the_contract(): void
    {
        $response = $this->postJson('/api/v1/auth/refresh', []);

        $response->assertStatus(422);
        $this->assertMatchesOperation($response, '/api/v1/auth/refresh', 'post');
    }

    // --------------------------------------------------------------- logout

    public function test_signing_out_matches_the_contract(): void
    {
        $pair = $this->signIn(self::PHONE);

        $response = $this->postJson('/api/v1/auth/logout', [], $this->bearer($pair['access_token']));

        $response->assertStatus(204);
        $this->assertMatchesOperation($response, '/api/v1/auth/logout', 'post');
    }

    public function test_an_unauthenticated_sign_out_matches_the_contract(): void
    {
        $response = $this->postJson('/api/v1/auth/logout', []);

        $response->assertStatus(401);
        $this->assertMatchesOperation($response, '/api/v1/auth/logout', 'post');
    }

    // ------------------------------------------------------------------- me

    public function test_the_account_response_matches_the_contract(): void
    {
        $pair = $this->signIn(self::PHONE);

        $response = $this->getJson('/api/v1/me', $this->bearer($pair['access_token']));

        $response->assertStatus(200);
        $this->assertMatchesOperation($response, '/api/v1/me', 'get');
    }

    public function test_an_unauthenticated_account_read_matches_the_contract(): void
    {
        $response = $this->getJson('/api/v1/me');

        $response->assertStatus(401);
        $this->assertMatchesOperation($response, '/api/v1/me', 'get');
    }

    public function test_a_suspended_account_read_matches_the_contract(): void
    {
        $account = $this->createAccount(self::PHONE);
        $pair = $this->signIn(self::PHONE);

        $account->status = AccountStatus::Suspended;
        $account->save();

        $response = $this->getJson('/api/v1/me', $this->bearer($pair['access_token']));

        $response->assertStatus(403);
        $this->assertMatchesOperation($response, '/api/v1/me', 'get');
    }

    /**
     * The documented surface and the served surface now agree exactly.
     */
    public function test_every_served_auth_route_is_documented(): void
    {
        /** @var array<string, mixed> $paths */
        $paths = self::contractDocument()['paths'];

        $served = [];
        foreach (app('router')->getRoutes()->getRoutes() as $route) {
            if (str_starts_with($route->uri(), 'api/v1/')) {
                $served[] = '/'.$route->uri();
            }
        }
        sort($served);

        self::assertSame([
            '/api/v1/auth/logout',
            '/api/v1/auth/otp',
            '/api/v1/auth/otp/verify',
            '/api/v1/auth/refresh',
            '/api/v1/me',
            '/api/v1/me/routes',
            '/api/v1/places',
            '/api/v1/routes',
            '/api/v1/routes/{routeId}/cancel',
        ], $served);

        // Nothing served is undocumented...
        foreach ($served as $path) {
            self::assertArrayHasKey($path, $paths, "$path is served but not documented");
        }

        // ...and nothing under /api/v1 is documented without being served,
        // EXCEPT what this list names.
        //
        // RideMate is spec-first: the contract for an endpoint lands before the
        // controller does, so between those two commits the document describes
        // something the router does not answer. That gap is legitimate and
        // temporary, and the way it stays both is that it has to be written
        // down here — the same reason SchemaAllowlistTest names its deferred
        // tables. A path nobody listed is a defect; a path listed forever is a
        // promise nobody kept.
        // EMPTY, and it must stay that way.
        //
        // Phase 10 finished the four operations it specified, so the gap
        // between contract and controller is closed. From here a documented
        // endpoint that nothing serves is a defect rather than a plan, and
        // adding a name to this list is how somebody would have to admit it.
        /** @var list<string> $awaitingImplementation */
        $awaitingImplementation = [];

        $documented = array_values(array_filter(
            array_keys($paths),
            static fn (string $path): bool => str_starts_with($path, '/api/v1/')
                && ! in_array($path, $awaitingImplementation, true),
        ));
        sort($documented);

        self::assertSame($documented, $served, 'the documented and served surfaces have diverged');

        // And the list itself cannot rot: every path on it must actually be
        // documented, so a served endpoint cannot be excused by a stale entry.
        foreach ($awaitingImplementation as $path) {
            self::assertArrayHasKey($path, $paths, "$path is excused but not documented");
            self::assertNotContains($path, $served, "$path is served and no longer awaiting anything");
        }
    }
}
