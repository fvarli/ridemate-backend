<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Osteel\OpenApi\Testing\ValidatorBuilder;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The authentication contract, before any endpoint implements it.
 *
 * Commit order puts this document first on purpose: the endpoints in the next
 * commit are written against a contract that has already been reviewed, rather
 * than the contract being back-filled from whatever the controllers happened to
 * return. That only means something if the document is checked NOW, so these
 * tests validate the spec and example payloads directly. Operation-level
 * response validation arrives with the endpoints.
 *
 * Much of what follows asserts ABSENCE. That is the point. A contract is as
 * much a promise about what is not disclosed as about what is, and every field
 * named below was considered and deliberately left out.
 */
final class AuthContractTest extends TestCase
{
    use ValidatesTheContract;

    /**
     * @param  array<string, mixed>|list<mixed>  $payload
     * @return TestResponse<JsonResponse>
     */
    private function body(array $payload): TestResponse
    {
        return TestResponse::fromBaseResponse(new JsonResponse($payload));
    }

    /**
     * The negative half of every schema assertion.
     *
     * Without it, `additionalProperties: false` could be missing and nothing
     * would notice — a schema that accepts everything passes every positive
     * test ever written against it.
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertSchemaRejects(array $payload, string $schema): void
    {
        try {
            $this->assertMatchesSchema($this->body($payload), $schema);
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }

        self::fail("the {$schema} schema accepted a payload it should have rejected");
    }

    // ------------------------------------------------------------- document

    public function test_the_document_is_valid_openapi(): void
    {
        ValidatorBuilder::fromYamlFile(self::contractPath())->getValidator();

        $this->addToAssertionCount(1);
    }

    public function test_it_is_openapi_three_one(): void
    {
        self::assertSame('3.1.0', self::contractDocument()['openapi']);
    }

    /**
     * Exactly these paths. Adding a twelfth has to be a deliberate edit here.
     */
    public function test_the_document_describes_exactly_the_served_surface(): void
    {
        /** @var array<string, mixed> $paths */
        $paths = self::contractDocument()['paths'];

        self::assertSame([
            '/health',
            '/ready',
            '/api/v1/auth/otp',
            '/api/v1/auth/otp/verify',
            '/api/v1/auth/refresh',
            '/api/v1/auth/logout',
            '/api/v1/me',
            '/api/v1/places',
            '/api/v1/routes',
            '/api/v1/me/routes',
            '/api/v1/routes/{routeId}/cancel',
        ], array_keys($paths));
    }

    /**
     * Nothing from a later phase has crept in.
     */
    public function test_no_unimplemented_endpoint_is_documented(): void
    {
        /** @var array<string, mixed> $paths */
        $paths = self::contractDocument()['paths'];
        $documented = implode(' ', array_keys($paths));

        // `routes` left this list in Phase 10, when route publication was
        // specified and then served. Everything still here belongs to a phase
        // that has not happened, and a path naming one would be describing an
        // intention rather than a service.
        foreach ([
            'trips', 'seat', 'requests', 'reviews', 'messages',
            'conversations', 'vehicles', 'safety', 'notifications', 'profile',
            'verification', 'sessions', 'devices', 'register', 'login',
        ] as $absent) {
            self::assertStringNotContainsString($absent, $documented);
        }
    }

    // ------------------------------------------------------------- security

    public function test_bearer_auth_is_declared_as_an_http_bearer_scheme(): void
    {
        /** @var array<string, mixed> $schemes */
        $schemes = self::contractDocument()['components']['securitySchemes'];

        self::assertSame(['bearerAuth'], array_keys($schemes));
        self::assertSame('http', $schemes['bearerAuth']['type']);
        self::assertSame('bearer', $schemes['bearerAuth']['scheme']);
    }

    /**
     * Bearer auth appears on exactly the operations that use an access token.
     *
     * Refresh is the one to watch: it is authenticated in the ordinary sense,
     * but NOT by a bearer token. Modelling it as bearer would invite clients to
     * put the refresh credential in an Authorization header, where it would
     * spread through logs and proxies that were only ever meant to see a
     * short-lived token.
     */
    #[DataProvider('operationSecurity')]
    public function test_operations_require_bearer_auth_only_where_intended(
        string $path,
        string $method,
        bool $expected,
    ): void {
        /** @var array<string, mixed> $operation */
        $operation = self::contractDocument()['paths'][$path][$method];

        self::assertArrayHasKey('security', $operation, "$method $path should state its security explicitly");

        $requiresBearer = $operation['security'] === [['bearerAuth' => []]];

        self::assertSame($expected, $requiresBearer, "$method $path bearer requirement");

        if (! $expected) {
            self::assertSame([], $operation['security'], "$method $path should be explicitly unauthenticated");
        }
    }

    /**
     * @return array<string, array{string, string, bool}>
     */
    public static function operationSecurity(): array
    {
        return [
            'request passcode' => ['/api/v1/auth/otp', 'post', false],
            'verify passcode' => ['/api/v1/auth/otp/verify', 'post', false],
            'refresh' => ['/api/v1/auth/refresh', 'post', false],
            'sign out' => ['/api/v1/auth/logout', 'post', true],
            'me' => ['/api/v1/me', 'get', true],
        ];
    }

    // ---------------------------------------------------------- error codes

    /**
     * The shared error contract did not have to change.
     *
     * Every authentication outcome maps onto a code Phase 8 already defined.
     * Pinning the enum makes adding a tenth a deliberate act with a diff to
     * review, rather than something that arrives with a feature.
     */
    public function test_the_error_code_enum_is_unchanged(): void
    {
        self::assertSame([
            'bad_request',
            'unauthenticated',
            'forbidden',
            'not_found',
            'method_not_allowed',
            'conflict',
            'validation_failed',
            'rate_limited',
            'internal_error',
        ], self::contractDocument()['components']['schemas']['ErrorCode']['enum']);
    }

    public function test_every_shared_error_response_uses_the_shared_schema(): void
    {
        /** @var array<string, mixed> $responses */
        $responses = self::contractDocument()['components']['responses'];

        self::assertSame([
            'Unauthenticated', 'Forbidden', 'ValidationFailed', 'NotFound', 'Conflict',
            'RateLimited', 'InternalError',
        ], array_keys($responses));

        foreach ($responses as $name => $response) {
            self::assertSame(
                '#/components/schemas/Error',
                $response['content']['application/json']['schema']['$ref'],
                "$name should reuse the shared Error schema",
            );
            self::assertArrayHasKey('X-Request-Id', $response['headers'], "$name should echo the request id");
        }
    }

    // -------------------------------------------------- passcode acceptance

    public function test_the_passcode_acceptance_body_is_exactly_one_field(): void
    {
        $this->assertMatchesSchema($this->body(['status' => 'accepted']), 'OtpAccepted');
    }

    /**
     * CARRIES WEIGHT. The 202 cannot grow a field that leaks.
     *
     * Each of these was a plausible thing to include, and each would turn the
     * endpoint into a membership oracle or a resend-timing oracle.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('leakyAcceptanceBodies')]
    public function test_the_passcode_acceptance_body_refuses_extra_fields(array $payload): void
    {
        $this->assertSchemaRejects($payload, 'OtpAccepted');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function leakyAcceptanceBodies(): array
    {
        return [
            'challenge id' => [['status' => 'accepted', 'challenge_id' => 'x']],
            'expiry' => [['status' => 'accepted', 'expires_at' => '2026-01-01T00:00:00Z']],
            'resend timing' => [['status' => 'accepted', 'resend_after' => 60]],
            'account existence' => [['status' => 'accepted', 'account_exists' => true]],
            'attempts remaining' => [['status' => 'accepted', 'attempts_remaining' => 5]],
            'wrong status value' => [['status' => 'sent']],
            'empty' => [[]],
        ];
    }

    // -------------------------------------------------------------- tokens

    public function test_a_token_pair_validates(): void
    {
        $this->assertMatchesSchema($this->body(self::tokenPair()), 'TokenPair');
    }

    #[DataProvider('tokenPairFields')]
    public function test_every_token_pair_field_is_required(string $field): void
    {
        $payload = self::tokenPair();
        unset($payload[$field]);

        $this->assertSchemaRejects($payload, 'TokenPair');
    }

    /**
     * @return array<string, array{string}>
     */
    public static function tokenPairFields(): array
    {
        return [
            'access_token' => ['access_token'],
            'refresh_token' => ['refresh_token'],
            'token_type' => ['token_type'],
            'expires_in' => ['expires_in'],
            'session_id' => ['session_id'],
        ];
    }

    /**
     * CARRIES WEIGHT. No token-family bookkeeping reaches the wire.
     *
     * Generation numbers, rotation timestamps and successor ids are how the
     * server detects a stolen credential. Publishing any of them would turn an
     * internal record into a promise, and would hand an attacker a map of the
     * chain they are trying to replay.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('leakyTokenPairs')]
    public function test_a_token_pair_refuses_internal_bookkeeping(array $payload): void
    {
        $this->assertSchemaRejects($payload, 'TokenPair');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function leakyTokenPairs(): array
    {
        $base = self::tokenPair();

        return [
            'generation' => [$base + ['generation' => 1]],
            'token id' => [$base + ['token_id' => '00000000-0000-7000-8000-000000000000']],
            'rotated at' => [$base + ['rotated_at' => null]],
            'successor' => [$base + ['succeeded_by_id' => null]],
            'account id' => [$base + ['account_id' => '00000000-0000-7000-8000-000000000002']],
            'refresh expiry' => [$base + ['refresh_expires_in' => 2592000]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function tokenPair(): array
    {
        return [
            'access_token' => 'rma_00000000-0000-7000-8000-000000000000.EXAMPLE',
            'refresh_token' => 'rmr_00000000-0000-7000-8000-000000000000.EXAMPLE',
            'token_type' => 'Bearer',
            'expires_in' => 900,
            'session_id' => '00000000-0000-7000-8000-000000000001',
        ];
    }

    public function test_the_token_type_is_pinned_to_bearer(): void
    {
        $this->assertSchemaRejects(['token_type' => 'bearer'] + self::tokenPair(), 'TokenPair');
    }

    // ------------------------------------------------------------------ me

    public function test_the_account_response_validates(): void
    {
        $this->assertMatchesSchema($this->body(['account' => self::account()]), 'Me');
    }

    /**
     * CARRIES WEIGHT. /me stays account-only for Phase 9.
     *
     * Every field below belongs to something RideMate does not have yet. A
     * contract describing them would be describing an intention, and a client
     * written against it would be waiting for data that never arrives.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('leakyMeBodies')]
    public function test_the_account_response_refuses_concepts_that_do_not_exist(array $payload): void
    {
        $this->assertSchemaRejects($payload, 'Me');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function leakyMeBodies(): array
    {
        $base = ['account' => self::account()];

        return [
            'profile' => [$base + ['profile' => ['display_name' => 'x']]],
            'verification' => [$base + ['verification' => []]],
            'trust score' => [$base + ['trust_score' => 60]],
            'session' => [$base + ['session' => []]],
            'devices' => [$base + ['devices' => []]],
            'token' => [$base + ['access_token' => 'x']],
            'account trust score' => [['account' => self::account() + ['trust_score' => 60]]],
            'account display name' => [['account' => self::account() + ['display_name' => 'x']]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function account(): array
    {
        return [
            'id' => '00000000-0000-7000-8000-000000000002',
            'phone_e164' => '+905321234567',
            'phone_verified_at' => '2026-08-23T10:14:02+00:00',
            'status' => 'active',
            'created_at' => '2026-08-23T10:14:02+00:00',
        ];
    }

    public function test_account_status_admits_only_the_two_implemented_states(): void
    {
        self::assertSame(
            ['active', 'suspended'],
            self::contractDocument()['components']['schemas']['AccountStatus']['enum'],
        );

        $this->assertSchemaRejects(['account' => ['status' => 'deleted'] + self::account()], 'Me');
    }

    // -------------------------------------------------------------- hygiene

    /**
     * Every credential example is unmistakably a placeholder.
     *
     * The failure this prevents is mundane and permanent: someone pastes a real
     * token from a local run into the document to "make the example realistic",
     * and it lives in the repository forever.
     */
    public function test_credential_examples_are_obvious_placeholders(): void
    {
        /** @var array<string, mixed> $schemas */
        $schemas = self::contractDocument()['components']['schemas'];

        foreach ([
            ['TokenPair', 'access_token'],
            ['TokenPair', 'refresh_token'],
            ['RefreshRequest', 'refresh_token'],
        ] as [$schema, $field]) {
            $examples = $schemas[$schema]['properties'][$field]['examples'];

            self::assertIsArray($examples);

            foreach ($examples as $example) {
                self::assertStringContainsString(
                    'EXAMPLE',
                    (string) $example,
                    "$schema.$field example should announce itself as fake",
                );
                self::assertStringContainsString(
                    '00000000-0000-7000-8000-000000000000',
                    (string) $example,
                    "$schema.$field example should use the placeholder identifier",
                );
            }
        }

        // A six-digit passcode cannot look fake, so it is pinned to a fixed
        // sentinel instead. Anything else in this field is a value someone
        // chose, and the only reason to choose one is that they saw it.
        self::assertSame(
            ['000000'],
            $schemas['OtpVerifyRequest']['properties']['code']['examples'],
        );
    }

    /**
     * No schema anywhere names a stored secret.
     *
     * Hashes are how credentials are kept, not how they are described, and a
     * field named for one would either be a leak or a lie.
     *
     * PROPERTY NAMES ONLY, not prose. The first version of this test scanned
     * the raw document and failed on its own documentation — `refresh_token`
     * is described as something to "store where the platform keeps secrets",
     * which is advice, not a field. That is the same trap the Phase 8
     * vocabulary guard avoids by inspecting identifier positions: a scanner
     * that reads explanations reports the explanation.
     */
    public function test_no_schema_property_names_a_stored_secret(): void
    {
        $names = self::propertyNames(self::contractDocument()['components']['schemas']);

        self::assertNotEmpty($names, 'the walker should have found properties');
        self::assertContains('access_token', $names, 'sanity: the walker sees real fields');

        foreach ($names as $name) {
            foreach ([
                'hash', 'password', 'secret', 'app_key', 'code_hash', 'salt',
                'generation', 'rotated', 'succeeded_by', 'attempts',
            ] as $forbidden) {
                self::assertStringNotContainsString(
                    $forbidden,
                    $name,
                    "the contract exposes a field named '{$name}'",
                );
            }
        }
    }

    /**
     * Every `properties` key anywhere in the document, at any depth.
     *
     * @return list<string>
     */
    private static function propertyNames(mixed $node): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];

        foreach ($node as $key => $value) {
            if ($key === 'properties' && is_array($value)) {
                foreach (array_keys($value) as $name) {
                    $found[] = strtolower((string) $name);
                }
            }

            $found = array_merge($found, self::propertyNames($value));
        }

        return $found;
    }
}
