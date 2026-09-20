<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Models\Account;
use App\Models\Registration;
use App\Otp\Email\EmailSender;
use App\Otp\Email\InMemoryEmailSender;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CleansCommittedRows;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * The registration contract, and the service obeying it.
 *
 * Two halves, as everywhere else in this directory. The document half says the
 * contract is coherent and refuses what it claims to refuse; the operation half
 * drives real requests and holds each response to the operation the document
 * describes for that path, method and status.
 *
 * The negative assertions carry the most weight here, for the reason
 * `ProfileContractTest` gives: a schema that accepts anything passes every
 * positive assertion ever written against it. What must be refused is specific
 * and security-shaped — a registration id published beside its credential, a
 * destination in a verify body, a proof timestamp anywhere, and a refusal
 * reason naming which identifier collided.
 *
 * Truncation, NOT RefreshDatabase: passcode delivery refuses to run inside a
 * transaction, and RefreshDatabase wraps every test in one.
 */
final class RegistrationContractTest extends TestCase
{
    use CleansCommittedRows;
    use DatabaseTruncation;
    use InteractsWithAuthEndpoints;
    use ValidatesTheContract;

    /** @var list<string> */
    protected array $tablesToTruncate = [
        'accounts',
        'auth_sessions',
        'auth_tokens',
        'otp_challenges',
        'registrations',
    ];

    private const START = '/api/v1/registrations';

    private const SEND = '/api/v1/registrations/otp';

    private const VERIFY = '/api/v1/registrations/otp/verify';

    private const COMPLETE = '/api/v1/registrations/complete';

    private const EMAIL = 'member@ridemate.invalid';

    private const PHONE = '+905321234567';

    private InMemoryEmailSender $email;

    protected function setUp(): void
    {
        parent::setUp();

        $this->email = new InMemoryEmailSender;
        $this->app->instance(EmailSender::class, $this->email);

        $this->bindTestSmsSender();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    // ------------------------------------------------------------- the document

    /**
     * CARRIES WEIGHT. The wire vocabulary is narrower than the domain's.
     *
     * The server distinguishes an address already registered from a number
     * already registered. The contract publishes neither string, because a
     * client able to tell them apart could aim a registration at an identifier
     * pair and read back which half belongs to a member.
     */
    public function test_the_refusal_vocabulary_publishes_no_identifier_specific_reason(): void
    {
        /** @var array<string, mixed> $schemas */
        $schemas = self::contractDocument()['components']['schemas'];

        self::assertSame([
            'not_fully_proven',
            'account_already_exists',
            'channel_already_bound',
        ], $schemas['RegistrationRefusalReason']['enum']);

        $document = (string) json_encode(self::contractDocument());

        foreach (['email_already_registered', 'phone_already_registered', 'registration_ended'] as $internal) {
            self::assertStringNotContainsString(
                $internal,
                $document,
                "the contract publishes the internal reason '{$internal}'",
            );
        }
    }

    /** The reason a client reads is one of the four domains', and now four. */
    public function test_the_registration_reasons_join_the_shared_reason_union(): void
    {
        /** @var array<string, mixed> $reason */
        $reason = self::contractDocument()['components']['schemas']['Error']['properties']['error']['properties']['details']['properties']['reason'];

        self::assertContains(
            ['$ref' => '#/components/schemas/RegistrationRefusalReason'],
            $reason['anyOf'],
        );
    }

    /**
     * CARRIES WEIGHT. The started body is closed around two fields.
     *
     * A registration id beside the credential would be a second name for the
     * registration that looks like an address and is not an authorization, and
     * the first client to put it in a path would be building the surface this
     * contract refuses to have.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('rejectedStartedBodies')]
    public function test_the_started_body_refuses_anything_beyond_its_two_fields(array $payload): void
    {
        $this->assertRejects($payload, 'RegistrationStarted');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rejectedStartedBodies(): array
    {
        $valid = [
            'registration_credential' => 'rmreg_00000000-0000-7000-8000-000000000000.EXAMPLE',
            'expires_at' => '2026-08-23T10:11:00Z',
        ];

        return [
            'a registration id' => [$valid + ['registration_id' => '00000000-0000-7000-8000-000000000000']],
            'a bare id' => [$valid + ['id' => '00000000-0000-7000-8000-000000000000']],
            'the credential hash' => [$valid + ['credential_hash' => 'deadbeef']],
            'an account id' => [$valid + ['account_id' => '00000000-0000-7000-8000-000000000000']],
            'a proof timestamp' => [$valid + ['email_verified_at' => null]],
            'a token' => [$valid + ['access_token' => 'rma_x']],
            'nothing at all' => [[]],
            'only the credential' => [['registration_credential' => 'rmreg_x']],
        ];
    }

    /** No registration schema anywhere publishes a proof timestamp. */
    public function test_no_registration_schema_publishes_a_proof_timestamp(): void
    {
        /** @var array<string, mixed> $schemas */
        $schemas = self::contractDocument()['components']['schemas'];

        foreach ($schemas as $name => $schema) {
            if (! str_starts_with((string) $name, 'Registration')) {
                continue;
            }

            $properties = array_keys($schema['properties'] ?? []);

            foreach (['email_verified_at', 'phone_verified_at', 'completed_at', 'account_id'] as $absent) {
                self::assertNotContains($absent, $properties, "{$name} publishes {$absent}");
            }
        }
    }

    /**
     * CARRIES WEIGHT. There is nowhere in a verify body to name a destination.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('rejectedVerifyBodies')]
    public function test_the_verify_body_refuses_a_destination(array $payload): void
    {
        $this->assertRejects($payload, 'RegistrationVerifyRequest');
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function rejectedVerifyBodies(): array
    {
        $valid = [
            'registration_credential' => 'rmreg_00000000-0000-7000-8000-000000000000.EXAMPLE',
            'channel' => 'email',
            'code' => '000000',
        ];

        return [
            'a destination' => [$valid + ['destination' => 'member@ridemate.invalid']],
            'an address' => [$valid + ['email' => 'member@ridemate.invalid']],
            'a number' => [$valid + ['phone' => '+905321234567']],
            'an invented channel' => [['channel' => 'carrier_pigeon'] + $valid],
            'a code that is not six digits' => [['code' => '12345'] + $valid],
        ];
    }

    /** The completion body accepts no identifier and no profile data. */
    public function test_the_completion_body_refuses_identifiers_and_profile_data(): void
    {
        $valid = ['registration_credential' => 'rmreg_00000000-0000-7000-8000-000000000000.EXAMPLE'];

        foreach ([
            'an address' => ['email' => 'member@ridemate.invalid'],
            'a number' => ['phone' => '+905321234567'],
            'a display name' => ['display_name' => 'İrem Yılmaz'],
            'an account id' => ['account_id' => '00000000-0000-7000-8000-000000000000'],
        ] as $why => $extra) {
            $this->assertRejects($valid + $extra, 'RegistrationCompleteRequest', $why);
        }
    }

    /** The credential example announces itself as fake, as every other does. */
    public function test_the_credential_example_is_an_obvious_placeholder(): void
    {
        /** @var array<string, mixed> $schemas */
        $schemas = self::contractDocument()['components']['schemas'];

        foreach ($schemas['RegistrationCredential']['examples'] as $example) {
            self::assertStringContainsString('EXAMPLE', (string) $example);
            self::assertStringContainsString('00000000-0000-7000-8000-000000000000', (string) $example);
        }
    }

    // ------------------------------------------------------------ the service

    public function test_the_started_response_matches_the_contract(): void
    {
        $response = $this->postJson(self::START);

        $response->assertStatus(201);
        $this->assertMatchesOperation($response, self::START, 'post');
        $this->assertMatchesSchema($response, 'RegistrationStarted');
    }

    public function test_the_accepted_passcode_matches_the_contract(): void
    {
        $response = $this->send($this->start(), 'email', self::EMAIL);

        $response->assertStatus(202);
        $this->assertMatchesOperation($response, self::SEND, 'post');
    }

    public function test_the_rejected_credential_matches_the_contract(): void
    {
        $response = $this->postJson(self::SEND, [
            'registration_credential' => 'rmreg_00000000-0000-7000-8000-000000000000.NOTASECRET',
            'channel' => 'email',
            'destination' => self::EMAIL,
        ]);

        $response->assertStatus(401);
        $this->assertMatchesOperation($response, self::SEND, 'post');
    }

    public function test_the_binding_conflict_matches_the_contract(): void
    {
        $credential = $this->start();
        $this->send($credential, 'email', self::EMAIL)->assertStatus(202);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));

        $response = $this->send($credential, 'email', 'someone-else@ridemate.invalid');

        $response->assertStatus(409);
        $this->assertMatchesOperation($response, self::SEND, 'post');
    }

    public function test_the_passcode_validation_failure_matches_the_contract(): void
    {
        $response = $this->send($this->start(), 'email', 'not-an-address');

        $response->assertStatus(422);
        $this->assertMatchesOperation($response, self::SEND, 'post');
    }

    public function test_the_passcode_rate_limit_matches_the_contract(): void
    {
        $this->send($this->start(), 'sms', self::PHONE)->assertStatus(202);

        $response = $this->send($this->start(), 'sms', self::PHONE);

        $response->assertStatus(429);
        $this->assertMatchesOperation($response, self::SEND, 'post');
    }

    /**
     * The `500` shape is still described, and SMS is what still produces it.
     *
     * Email no longer can: a provider that rejected one recipient and accepted
     * another would otherwise publish that difference as a status code. SMS has
     * no provider at all, so its sender refuses every send identically and the
     * documented internal-error response stays exercised by a real request.
     */
    public function test_the_delivery_failure_matches_the_contract(): void
    {
        $this->sms->fail();

        $response = $this->send($this->start(), 'sms', self::PHONE);

        $response->assertStatus(500);
        $this->assertMatchesOperation($response, self::SEND, 'post');
    }

    /**
     * CARRIES WEIGHT. A failed email delivery answers the documented `202`.
     *
     * Held to the same operation as a successful send, so the contract cannot
     * describe two shapes and let the provider choose between them.
     */
    public function test_a_failed_email_delivery_matches_the_accepted_contract(): void
    {
        $this->email->fail();

        $response = $this->send($this->start(), 'email', self::EMAIL);

        $response->assertStatus(202);
        $this->assertMatchesOperation($response, self::SEND, 'post');
    }

    public function test_the_verified_response_matches_the_contract(): void
    {
        $credential = $this->start();
        $this->send($credential, 'email', self::EMAIL)->assertStatus(202);

        $response = $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => 'email',
            'code' => $this->lastEmailCode(),
        ]);

        $response->assertStatus(204);
        $this->assertMatchesOperation($response, self::VERIFY, 'post');
    }

    public function test_the_rejected_passcode_matches_the_contract(): void
    {
        $credential = $this->start();
        $this->send($credential, 'email', self::EMAIL)->assertStatus(202);

        $response = $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => 'email',
            'code' => '999999',
        ]);

        $response->assertStatus(401);
        $this->assertMatchesOperation($response, self::VERIFY, 'post');
    }

    public function test_the_completion_response_matches_the_contract(): void
    {
        $response = $this->postJson(self::COMPLETE, [
            'registration_credential' => $this->fullyProven(),
        ]);

        $response->assertStatus(200);
        $this->assertMatchesOperation($response, self::COMPLETE, 'post');
        $this->assertMatchesSchema($response, 'TokenPair');
    }

    public function test_the_unproven_completion_matches_the_contract(): void
    {
        $response = $this->postJson(self::COMPLETE, [
            'registration_credential' => $this->start(),
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.details.reason', 'not_fully_proven');
        $this->assertMatchesOperation($response, self::COMPLETE, 'post');
    }

    public function test_the_collision_matches_the_contract(): void
    {
        $account = new Account;
        $account->email = self::EMAIL;
        $account->email_verified_at = CarbonImmutable::now();
        $account->phone_e164 = self::PHONE;
        $account->phone_verified_at = CarbonImmutable::now();
        $account->save();

        $response = $this->postJson(self::COMPLETE, [
            'registration_credential' => $this->fullyProven(),
        ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.details.reason', 'account_already_exists');
        $this->assertMatchesOperation($response, self::COMPLETE, 'post');

        self::assertNull(Registration::query()->firstOrFail()->completed_at);
    }

    public function test_the_replayed_completion_matches_the_contract(): void
    {
        $credential = $this->fullyProven();

        $this->postJson(self::COMPLETE, ['registration_credential' => $credential])->assertStatus(200);

        $response = $this->postJson(self::COMPLETE, ['registration_credential' => $credential]);

        $response->assertStatus(401);
        $this->assertMatchesOperation($response, self::COMPLETE, 'post');
    }

    // --------------------------------------------------------------- helpers

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertRejects(array $payload, string $schema, string $why = 'the schema should have refused this'): void
    {
        try {
            $this->assertMatchesSchema(
                TestResponse::fromBaseResponse(new JsonResponse($payload)),
                $schema,
            );
        } catch (AssertionFailedError) {
            $this->addToAssertionCount(1);

            return;
        }

        self::fail($why);
    }

    private function start(): string
    {
        $response = $this->postJson(self::START);
        $response->assertStatus(201);

        return (string) $response->json('registration_credential');
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function send(string $credential, string $channel, string $destination): TestResponse
    {
        return $this->postJson(self::SEND, [
            'registration_credential' => $credential,
            'channel' => $channel,
            'destination' => $destination,
        ]);
    }

    private function fullyProven(): string
    {
        $credential = $this->start();

        $this->send($credential, 'email', self::EMAIL)->assertStatus(202);
        $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => 'email',
            'code' => $this->lastEmailCode(),
        ])->assertStatus(204);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));

        $this->send($credential, 'sms', self::PHONE)->assertStatus(202);
        $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => 'sms',
            'code' => (string) $this->sms->lastCode(),
        ])->assertStatus(204);

        return $credential;
    }

    private function lastEmailCode(): string
    {
        $code = $this->email->lastCode();
        self::assertNotNull($code, 'the email sender should have received a passcode');

        return $code;
    }
}
