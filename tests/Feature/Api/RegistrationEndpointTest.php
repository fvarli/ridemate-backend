<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Account;
use App\Models\OtpChallenge;
use App\Models\Registration;
use App\Otp\Email\EmailSender;
use App\Otp\Email\InMemoryEmailSender;
use App\Support\ApiError;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Http\JsonResponse;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CleansCommittedRows;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * The four registration endpoints over real HTTP.
 *
 * S4a–S4d proved the domain. This file proves the only thing the domain could
 * not: that the boundary in front of it publishes the state machine truthfully
 * and refuses to answer the questions it must not answer.
 *
 * Four properties carry it.
 *
 * The first is that nothing exists until it is earned. Starting creates no
 * account and no session; proving one channel creates neither; only completion
 * does, and exactly once.
 *
 * The second is that the destination never crosses the verify boundary. There
 * is no field for one, and a body that invents one is refused rather than read
 * — so a code earned on an address the caller controls cannot be attached to a
 * registration naming somebody else's, even by a client that tries.
 *
 * The third is that the two OTP namespaces do not touch through HTTP. A
 * registration code presented at `POST /api/v1/auth/otp/verify` opens no
 * account, and a sign-in code presented here proves nothing — while the
 * destination-wide abuse budget still spans both, because what it protects is
 * the handset rather than the flow.
 *
 * The fourth is the enumeration posture. A collision with an existing account
 * answers one string whichever identifier collided, an ended credential answers
 * a `401` that cannot be told from an unknown one, and nothing anywhere names
 * an address, a number, a passcode or a credential.
 *
 * Truncation, NOT RefreshDatabase — for the reason `RequestPasscodeEndpointTest`
 * gives: passcode delivery refuses to run inside a transaction, and
 * RefreshDatabase wraps every test in exactly one.
 */
final class RegistrationEndpointTest extends TestCase
{
    use CleansCommittedRows;
    use DatabaseTruncation;
    use InteractsWithAuthEndpoints;

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

    private const OTHER_EMAIL = 'someone-else@ridemate.invalid';

    private const PHONE = '+905321234567';

    private const OTHER_PHONE = '+905329876543';

    private InMemoryEmailSender $email;

    protected function setUp(): void
    {
        parent::setUp();

        $this->email = new InMemoryEmailSender;
        $this->app->instance(EmailSender::class, $this->email);

        // Binds $this->sms, which the sign-in helpers also use — the crossing
        // tests below need both paths reachable in one test.
        $this->bindTestSmsSender();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();

        // Before parent::tearDown(), which destroys the application.
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    // ------------------------------------------------------------------ start

    /**
     * CARRIES WEIGHT. A registration is not an account, and starting one
     * creates nothing that could be mistaken for a principal.
     */
    public function test_starting_creates_a_registration_and_nothing_else(): void
    {
        $response = $this->postJson(self::START);

        $response->assertStatus(201);
        $response->assertJsonStructure(['registration_credential', 'expires_at']);

        self::assertSame(1, Registration::query()->count());
        self::assertSame(0, Account::query()->count(), 'starting created an account');
        self::assertSame(0, DB::table('auth_sessions')->count(), 'starting opened a session');
        self::assertSame(0, DB::table('auth_tokens')->count(), 'starting issued a token');

        $registration = Registration::query()->firstOrFail();
        self::assertNull($registration->email, 'starting bound an address');
        self::assertNull($registration->phone_e164, 'starting bound a number');
        self::assertNull($registration->completed_at);
    }

    /** The credential is the capability. The row id is not published beside it. */
    public function test_the_started_body_publishes_the_credential_and_its_deadline_only(): void
    {
        $body = $this->postJson(self::START)->json();

        self::assertIsArray($body);
        self::assertSame(['registration_credential', 'expires_at'], array_keys($body));

        $registration = Registration::query()->firstOrFail();

        $encoded = (string) json_encode($body);
        self::assertStringNotContainsString($registration->credential_hash, $encoded);

        // The id IS inside the credential — that is what makes resolution a
        // primary-key read — but it appears nowhere as a field of its own.
        self::assertArrayNotHasKey('registration_id', $body);
        self::assertArrayNotHasKey('id', $body);
    }

    public function test_the_deadline_is_the_configured_lifetime(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23T09:41:00Z'));

        $expiresAt = $this->postJson(self::START)->json('expires_at');

        self::assertSame(
            CarbonImmutable::now()->addSeconds((int) config('ridemate.registration.ttl'))->toAtomString(),
            $expiresAt,
        );
    }

    /** No body is read, so nothing a caller supplies can change the answer. */
    public function test_starting_ignores_anything_the_caller_sends(): void
    {
        $this->postJson(self::START, ['email' => self::EMAIL, 'phone' => self::PHONE])
            ->assertStatus(201);

        $registration = Registration::query()->firstOrFail();

        self::assertNull($registration->email, 'a start body bound an address');
        self::assertNull($registration->phone_e164, 'a start body bound a number');
    }

    // ------------------------------------------------------------- the binding

    /**
     * CARRIES WEIGHT. The canonical identifier is bound at the moment a code is
     * sent to it, and in its canonical form.
     */
    public function test_sending_binds_the_canonical_destination_for_that_channel(): void
    {
        $credential = $this->start();

        $this->send($credential, 'sms', '0532 123 45 67')->assertStatus(202);

        $registration = Registration::query()->firstOrFail();
        self::assertSame(self::PHONE, $registration->phone_e164, 'the number was not canonicalized');
        self::assertNull($registration->email, 'an SMS send bound an address');
        self::assertNull($registration->phone_verified_at, 'sending proved possession');

        $this->send($credential, 'email', 'Member@RideMate.INVALID')->assertStatus(202);

        $registration->refresh();
        self::assertSame(self::EMAIL, $registration->email, 'the address was not canonicalized');
        self::assertNull($registration->email_verified_at, 'sending proved possession');
    }

    public function test_a_resend_to_the_same_destination_is_accepted(): void
    {
        $credential = $this->start();

        $this->send($credential, 'email', self::EMAIL)->assertStatus(202);

        // Past the sixty-second cooldown, which is a destination-wide rule and
        // not the binding rule under test here.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));

        $this->send($credential, 'email', self::EMAIL)->assertStatus(202);

        self::assertSame(self::EMAIL, Registration::query()->firstOrFail()->email);
    }

    /**
     * CARRIES WEIGHT. Binding is write-once, and the refusal names neither
     * destination.
     */
    public function test_a_second_destination_on_one_channel_is_refused_without_naming_either(): void
    {
        $credential = $this->start();
        $this->send($credential, 'email', self::EMAIL)->assertStatus(202);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));

        $response = $this->send($credential, 'email', self::OTHER_EMAIL);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', ApiError::CONFLICT);
        $response->assertJsonPath('error.details.reason', 'channel_already_bound');

        $body = (string) $response->getContent();
        self::assertStringNotContainsString(self::EMAIL, $body);
        self::assertStringNotContainsString(self::OTHER_EMAIL, $body);

        self::assertSame(self::EMAIL, Registration::query()->firstOrFail()->email);
    }

    // ------------------------------------------------------------ proving them

    /**
     * CARRIES WEIGHT. Both orders work, and neither creates anything.
     *
     * @param  list<string>  $order
     */
    #[DataProvider('channelOrders')]
    public function test_either_channel_may_be_proven_first(array $order): void
    {
        $credential = $this->start();

        foreach ($order as $channel) {
            $this->prove($credential, $channel);

            // Time moves between the two so the destination-wide cooldown,
            // which is not what this test is about, does not refuse the second.
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));
        }

        $registration = Registration::query()->firstOrFail();

        self::assertTrue($registration->isFullyProven());
        self::assertSame(0, Account::query()->count(), 'proof created an account');
        self::assertSame(0, DB::table('auth_tokens')->count(), 'proof issued a token');
        self::assertNull($registration->completed_at, 'proof completed the registration');
    }

    /**
     * @return array<string, array{list<string>}>
     */
    public static function channelOrders(): array
    {
        return [
            'email then sms' => [['email', 'sms']],
            'sms then email' => [['sms', 'email']],
        ];
    }

    /**
     * CARRIES WEIGHT — THE PROPERTY THE WHOLE BOUNDARY EXISTS FOR.
     *
     * A caller holding a live code for an address it controls cannot attach the
     * proof to a registration naming a different one. The destination it offers
     * is not read, and the verification fails against the registration's own.
     */
    public function test_verification_never_takes_a_destination_from_the_caller(): void
    {
        $victim = $this->start();
        $this->send($victim, 'email', self::EMAIL)->assertStatus(202);

        $attacker = $this->start();
        $this->send($attacker, 'email', self::OTHER_EMAIL)->assertStatus(202);

        // The attacker's own code, for the attacker's own address.
        $code = $this->lastEmailCode();

        // Every name a client might reach for. None of them is read: the
        // action's signature has nowhere to put a destination, and the contract
        // declares the body closed. They change nothing — the code is still
        // judged against the destination the VICTIM's registration names.
        $withDestination = $this->postJson(self::VERIFY, [
            'registration_credential' => $victim,
            'channel' => 'email',
            'code' => $code,
            'destination' => self::OTHER_EMAIL,
            'email' => self::OTHER_EMAIL,
            'phone' => self::OTHER_PHONE,
        ]);

        $withoutDestination = $this->postJson(self::VERIFY, [
            'registration_credential' => $victim,
            'channel' => 'email',
            'code' => $code,
        ]);

        $withDestination->assertStatus(401);
        $withoutDestination->assertStatus(401);

        // Byte for byte the same answer, so the extra keys did not reach a
        // branch — not even one that refused them differently.
        self::assertSame(
            $this->withoutRequestId($withDestination),
            $this->withoutRequestId($withoutDestination),
            'a caller-supplied destination changed the answer',
        );

        foreach (Registration::query()->get() as $registration) {
            self::assertNull($registration->email_verified_at, 'a proof was attached');
        }

        // The victim still names its own address, and the attacker still names
        // its own. Nothing was rebound by the body.
        $addresses = Registration::query()->pluck('email')->sort()->values()->all();
        self::assertSame([self::EMAIL, self::OTHER_EMAIL], $addresses);
    }

    /**
     * CARRIES WEIGHT. One registration's code cannot prove another, even when
     * both named the same address.
     */
    public function test_cross_registration_codes_do_not_verify(): void
    {
        $first = $this->start();
        $this->send($first, 'email', self::EMAIL)->assertStatus(202);
        $firstCode = $this->lastEmailCode();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));

        $second = $this->start();
        $this->send($second, 'email', self::EMAIL)->assertStatus(202);
        $secondCode = $this->lastEmailCode();

        $this->postJson(self::VERIFY, [
            'registration_credential' => $second,
            'channel' => 'email',
            'code' => $firstCode,
        ])->assertStatus(401);

        $this->postJson(self::VERIFY, [
            'registration_credential' => $first,
            'channel' => 'email',
            'code' => $secondCode,
        ])->assertStatus(401);

        foreach (Registration::query()->get() as $registration) {
            self::assertNull($registration->email_verified_at);
        }
    }

    public function test_a_wrong_code_is_refused_and_spends_an_attempt(): void
    {
        $credential = $this->start();
        $this->send($credential, 'sms', self::PHONE)->assertStatus(202);

        $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => 'sms',
            'code' => '999999',
        ])->assertStatus(401);

        self::assertSame(1, (int) OtpChallenge::query()->firstOrFail()->attempts);
        self::assertNull(Registration::query()->firstOrFail()->phone_verified_at);
    }

    public function test_an_expired_code_is_refused(): void
    {
        $credential = $this->start();
        $this->send($credential, 'sms', self::PHONE)->assertStatus(202);
        $code = $this->sms->lastCode();

        CarbonImmutable::setTestNow(
            CarbonImmutable::now()->addSeconds((int) config('ridemate.otp.ttl') + 1),
        );

        $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => 'sms',
            'code' => (string) $code,
        ])->assertStatus(401);

        self::assertNull(Registration::query()->firstOrFail()->phone_verified_at);
    }

    /** A consumed code cannot be spent again, and cannot refresh a proof. */
    public function test_a_consumed_code_is_refused_and_the_proof_does_not_move(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-23T09:41:00Z'));

        $credential = $this->start();
        $this->send($credential, 'sms', self::PHONE)->assertStatus(202);
        $code = (string) $this->sms->lastCode();

        $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => 'sms',
            'code' => $code,
        ])->assertStatus(204);

        $provenAt = Registration::query()->firstOrFail()->phone_verified_at;
        self::assertNotNull($provenAt);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(30));

        $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => 'sms',
            'code' => $code,
        ])->assertStatus(401);

        $stillProvenAt = Registration::query()->firstOrFail()->phone_verified_at;
        self::assertNotNull($stillProvenAt);
        self::assertTrue(
            $provenAt->equalTo($stillProvenAt),
            'a second verification moved the proof timestamp',
        );
    }

    /** A channel nothing was ever sent on cannot be verified. */
    public function test_an_unbound_channel_cannot_be_verified(): void
    {
        $credential = $this->start();
        $this->send($credential, 'sms', self::PHONE)->assertStatus(202);

        $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => 'email',
            'code' => (string) $this->sms->lastCode(),
        ])->assertStatus(401);

        self::assertNull(Registration::query()->firstOrFail()->email_verified_at);
    }

    // ------------------------------------------------------------- credentials

    /**
     * CARRIES WEIGHT. Every unusable credential is one answer.
     *
     * Malformed, unknown, the wrong secret and — below — expired and completed
     * all arrive as the same `401` with no `details`, so a credential somebody
     * does not hold cannot be probed for whether it ever existed or how it
     * ended.
     */
    #[DataProvider('unusableCredentials')]
    public function test_an_unusable_credential_is_one_answer(string $case): void
    {
        $credential = $this->credentialFor($case);

        foreach ([
            [self::SEND, ['channel' => 'email', 'destination' => self::EMAIL]],
            [self::VERIFY, ['channel' => 'email', 'code' => '123456']],
            [self::COMPLETE, []],
        ] as [$path, $payload]) {
            $response = $this->postJson($path, ['registration_credential' => $credential] + $payload);

            $response->assertStatus(401);
            $response->assertJsonPath('error.code', ApiError::UNAUTHENTICATED);
            $response->assertJsonMissingPath('error.details');
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function unusableCredentials(): array
    {
        return [
            'not a credential at all' => ['garbage'],
            'an access token prefix' => ['access'],
            'the right shape, unknown id' => ['unknown'],
            'a real id, wrong secret' => ['wrong-secret'],
        ];
    }

    /**
     * CARRIES WEIGHT. An expired registration and a completed one answer the
     * same thing, so completion history cannot be read back.
     */
    public function test_an_expired_and_a_completed_credential_are_indistinguishable(): void
    {
        $expired = $this->start();
        $completed = $this->fullyProven();

        $this->postJson(self::COMPLETE, ['registration_credential' => $completed])
            ->assertStatus(200);

        CarbonImmutable::setTestNow(
            CarbonImmutable::now()->addSeconds((int) config('ridemate.registration.ttl') + 1),
        );

        $first = $this->postJson(self::COMPLETE, ['registration_credential' => $expired]);
        $second = $this->postJson(self::COMPLETE, ['registration_credential' => $completed]);

        $first->assertStatus(401);
        $second->assertStatus(401);

        self::assertSame(
            $this->withoutRequestId($first),
            $this->withoutRequestId($second),
            'an expired credential can be told from a completed one',
        );
    }

    // -------------------------------------------------------------- completion

    /**
     * CARRIES WEIGHT. The whole slice in one assertion block.
     *
     * Both proofs, one account, one session, and a body that is the ordinary
     * token pair and nothing registration-shaped.
     */
    public function test_completion_creates_exactly_one_account_and_returns_only_auth_material(): void
    {
        $credential = $this->fullyProven();

        $response = $this->postJson(self::COMPLETE, [
            'registration_credential' => $credential,
            'device_name' => 'Pixel 8',
            'platform' => 'android',
        ]);

        $response->assertStatus(200);

        $body = $response->json();
        self::assertIsArray($body);
        self::assertSame([
            'access_token', 'refresh_token', 'token_type', 'expires_in', 'session_id',
        ], array_keys($body));

        self::assertSame(1, Account::query()->count());
        self::assertSame(1, DB::table('auth_sessions')->count());

        $account = Account::query()->firstOrFail();
        self::assertSame(self::EMAIL, $account->email);
        self::assertSame(self::PHONE, $account->phone_e164);

        $registration = Registration::query()->firstOrFail();
        self::assertSame($account->id, $registration->account_id);
        self::assertNotNull($registration->completed_at);

        // Both proof timestamps are the registration's, not the completion's.
        $emailProvenAt = $registration->email_verified_at;
        $phoneProvenAt = $registration->phone_verified_at;
        self::assertNotNull($emailProvenAt);
        self::assertNotNull($phoneProvenAt);
        self::assertNotNull($account->email_verified_at);
        self::assertTrue($emailProvenAt->equalTo($account->email_verified_at));
        self::assertTrue($phoneProvenAt->equalTo($account->phone_verified_at));

        // The body says nothing about the registration it came from.
        $encoded = (string) json_encode($body);
        self::assertStringNotContainsString($registration->id, $encoded);
        self::assertStringNotContainsString(self::EMAIL, $encoded);
        self::assertStringNotContainsString(self::PHONE, $encoded);
    }

    /** The pair is a normal session, and behaves like every other one. */
    public function test_the_returned_pair_authenticates_the_ordinary_account_endpoints(): void
    {
        $credential = $this->fullyProven();

        $pair = $this->postJson(self::COMPLETE, ['registration_credential' => $credential])->json();
        self::assertIsArray($pair);

        $this->getJson('/api/v1/me', $this->bearer((string) $pair['access_token']))
            ->assertStatus(200)
            ->assertJsonPath('account.phone_e164', self::PHONE);
    }

    /**
     * CARRIES WEIGHT. A replay mints nothing and returns nothing.
     */
    public function test_completion_cannot_be_replayed_for_a_second_session(): void
    {
        $credential = $this->fullyProven();

        $this->postJson(self::COMPLETE, ['registration_credential' => $credential])
            ->assertStatus(200);

        $replay = $this->postJson(self::COMPLETE, ['registration_credential' => $credential]);

        $replay->assertStatus(401);
        $replay->assertJsonMissingPath('access_token');
        $replay->assertJsonMissingPath('refresh_token');

        self::assertSame(1, Account::query()->count(), 'a replay created a second account');
        self::assertSame(1, DB::table('auth_sessions')->count(), 'a replay opened a second session');
    }

    #[DataProvider('incompleteProofs')]
    public function test_completion_before_both_proofs_is_refused(?string $proven): void
    {
        $credential = $this->start();

        $this->send($credential, 'email', self::EMAIL)->assertStatus(202);
        $this->send($credential, 'sms', self::PHONE)->assertStatus(202);

        if ($proven !== null) {
            $this->postJson(self::VERIFY, [
                'registration_credential' => $credential,
                'channel' => $proven,
                'code' => $proven === 'email' ? $this->lastEmailCode() : (string) $this->sms->lastCode(),
            ])->assertStatus(204);
        }

        $response = $this->postJson(self::COMPLETE, ['registration_credential' => $credential]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', ApiError::CONFLICT);
        $response->assertJsonPath('error.details.reason', 'not_fully_proven');

        self::assertSame(0, Account::query()->count());
        self::assertNull(Registration::query()->firstOrFail()->completed_at);
    }

    /**
     * @return array<string, array{string|null}>
     */
    public static function incompleteProofs(): array
    {
        return [
            'neither channel proven' => [null],
            'only the address proven' => ['email'],
            'only the number proven' => ['sms'],
        ];
    }

    /**
     * CARRIES WEIGHT — THE ENUMERATION DECISION.
     *
     * An account already holding the address and an account already holding the
     * number produce the SAME public answer, although the domain knows which
     * collided. A caller able to tell them apart could aim a registration at an
     * identifier pair and read back which half belongs to a member.
     */
    public function test_both_collisions_answer_one_non_enumerating_reason(): void
    {
        $addressTaken = $this->collide(self::EMAIL, self::OTHER_PHONE);
        $numberTaken = $this->collide(self::OTHER_EMAIL, self::PHONE);

        self::assertSame($addressTaken, $numberTaken, 'the two collisions can be told apart');
    }

    /**
     * Completes a registration against an account that already holds BOTH
     * identifiers, using the pair given, and returns the refusal body.
     *
     * @return array<string, mixed>
     */
    private function collide(string $email, string $phone): array
    {
        $this->truncateCommittedAuthRows();

        // A member who already exists, holding both identifiers.
        $existing = new Account;
        $existing->email = self::EMAIL;
        $existing->email_verified_at = CarbonImmutable::now();
        $existing->phone_e164 = self::PHONE;
        $existing->phone_verified_at = CarbonImmutable::now();
        $existing->save();

        $credential = $this->fullyProven($email, $phone);

        $response = $this->postJson(self::COMPLETE, ['registration_credential' => $credential]);

        $response->assertStatus(409);
        $response->assertJsonPath('error.code', ApiError::CONFLICT);
        $response->assertJsonPath('error.details.reason', 'account_already_exists');

        $body = (string) $response->getContent();
        foreach ([self::EMAIL, self::OTHER_EMAIL, self::PHONE, self::OTHER_PHONE] as $identifier) {
            self::assertStringNotContainsString($identifier, $body, 'the refusal named an identifier');
        }

        // The registration stays open, and the existing account is untouched —
        // never adopted, merged or overwritten.
        $registration = Registration::query()->firstOrFail();
        self::assertNull($registration->completed_at, 'a collision completed the registration');
        self::assertNull($registration->account_id);
        self::assertSame(1, Account::query()->count(), 'a collision created an account');
        self::assertSame($existing->id, Account::query()->firstOrFail()->id);

        return $this->withoutRequestId($response);
    }

    // ------------------------------------------------- the two OTP namespaces

    /**
     * CARRIES WEIGHT. A registration passcode is not a sign-in passcode.
     */
    public function test_a_registration_passcode_cannot_sign_anyone_in(): void
    {
        $credential = $this->start();
        $this->send($credential, 'sms', self::PHONE)->assertStatus(202);

        $this->verifyPasscode(self::PHONE, (string) $this->sms->lastCode())
            ->assertStatus(401);

        self::assertSame(0, Account::query()->count(), 'a registration code opened an account');
        self::assertSame(0, DB::table('auth_sessions')->count());
    }

    /** And the crossing does not work the other way either. */
    public function test_a_sign_in_passcode_cannot_prove_a_registration(): void
    {
        $code = $this->requestPasscode(self::PHONE);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));

        $credential = $this->start();
        $this->send($credential, 'sms', self::PHONE)->assertStatus(202);

        $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => 'sms',
            'code' => $code,
        ])->assertStatus(401);

        self::assertNull(Registration::query()->firstOrFail()->phone_verified_at);
    }

    /**
     * CARRIES WEIGHT. The abuse budget protects the handset, not the flow.
     *
     * Registrations are free to create and nothing is unique across them, so a
     * budget that reset per registration would be no budget at all.
     */
    public function test_the_destination_budget_spans_registrations_and_sign_in(): void
    {
        $cap = (int) config('ridemate.otp.max_per_destination_per_hour');
        $cooldown = (int) config('ridemate.otp.resend_cooldown');

        // One sign-in request, then the rest from fresh registrations — a new
        // one each time, so nothing but the destination is shared.
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertStatus(202);

        for ($sent = 1; $sent < $cap; $sent++) {
            CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($cooldown + 1));
            $this->send($this->start(), 'sms', self::PHONE)->assertStatus(202);
        }

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds($cooldown + 1));

        $refused = $this->send($this->start(), 'sms', self::PHONE);
        $refused->assertStatus(429);
        $refused->assertJsonPath('error.code', ApiError::RATE_LIMITED);

        // And the sign-in path is out of budget for that handset too.
        $this->postJson('/api/v1/auth/otp', ['phone' => self::PHONE])->assertStatus(429);
    }

    /** The cooldown is destination-wide as well, not per registration. */
    public function test_the_resend_cooldown_cannot_be_reset_by_a_new_registration(): void
    {
        $this->send($this->start(), 'sms', self::PHONE)->assertStatus(202);

        $this->send($this->start(), 'sms', self::PHONE)->assertStatus(429);
    }

    // ------------------------------------------------------ the other boundary

    /**
     * CARRIES WEIGHT. A registration credential is not an account credential,
     * and no registration endpoint is an account endpoint.
     */
    public function test_a_registration_credential_authenticates_nothing(): void
    {
        $credential = $this->start();

        foreach (['/api/v1/me', '/api/v1/me/profile'] as $path) {
            $this->getJson($path, $this->bearer($credential))->assertStatus(401);
        }

        $this->postJson('/api/v1/auth/logout', [], $this->bearer($credential))->assertStatus(401);

        // And after completion, when an account genuinely exists behind it.
        $completed = $this->fullyProven(self::OTHER_EMAIL, self::OTHER_PHONE);
        $this->postJson(self::COMPLETE, ['registration_credential' => $completed])->assertStatus(200);

        $this->getJson('/api/v1/me', $this->bearer($completed))->assertStatus(401);
    }

    /** No registration route is authenticated, and none may become one. */
    public function test_no_registration_route_carries_account_authentication(): void
    {
        $seen = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            if (! str_starts_with($route->uri(), 'api/v1/registrations')) {
                continue;
            }

            $seen[] = $route->uri();

            self::assertNotContains('auth.token', $route->gatherMiddleware(), $route->uri());
            self::assertSame(['POST'], array_values(array_diff($route->methods(), ['HEAD'])));
        }

        sort($seen);
        self::assertSame([
            'api/v1/registrations',
            'api/v1/registrations/complete',
            'api/v1/registrations/otp',
            'api/v1/registrations/otp/verify',
        ], $seen);
    }

    /** An access token is not a registration credential either. */
    public function test_an_access_token_cannot_advance_a_registration(): void
    {
        $pair = $this->signIn(self::OTHER_PHONE);

        $this->postJson(self::SEND, [
            'registration_credential' => $pair['access_token'],
            'channel' => 'email',
            'destination' => self::EMAIL,
        ])->assertStatus(401);
    }

    // ------------------------------------------------------------ the existing

    /**
     * `/auth/*` is what it was. A first-time member still becomes a phone-only
     * account through it, with no email and no registration behind it.
     */
    public function test_phone_sign_in_is_unchanged(): void
    {
        $pair = $this->signIn(self::PHONE);

        $account = Account::query()->firstOrFail();
        self::assertSame(self::PHONE, $account->phone_e164);
        self::assertNull($account->email, 'sign-in invented an address');
        self::assertSame(0, Registration::query()->count(), 'sign-in created a registration');

        $this->getJson('/api/v1/me', $this->bearer($pair['access_token']))
            ->assertStatus(200)
            ->assertJsonMissingPath('account.email');
    }

    // --------------------------------------------------------------- hygiene

    /**
     * CARRIES WEIGHT. Nothing sensitive reaches the logging pipeline.
     */
    public function test_the_registration_endpoints_log_no_identifier_code_or_credential(): void
    {
        /** @var list<MessageLogged> $logged */
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged): void {
            $logged[] = $e;
        });

        $credential = $this->start();
        $this->send($credential, 'email', self::EMAIL)->assertStatus(202);
        $emailCode = $this->lastEmailCode();
        $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => 'email',
            'code' => $emailCode,
        ])->assertStatus(204);

        $this->send($credential, 'sms', self::PHONE)->assertStatus(202);
        $smsCode = (string) $this->sms->lastCode();
        $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => 'sms',
            'code' => $smsCode,
        ])->assertStatus(204);

        $pair = $this->postJson(self::COMPLETE, ['registration_credential' => $credential])->json();
        self::assertIsArray($pair);

        self::assertNotEmpty($logged, 'the request logger should have recorded something');

        foreach ($logged as $entry) {
            $line = $entry->message.' '.json_encode($entry->context);

            foreach ([
                self::EMAIL,
                self::PHONE,
                $emailCode,
                $smsCode,
                $credential,
                (string) $pair['access_token'],
                (string) $pair['refresh_token'],
            ] as $secret) {
                self::assertStringNotContainsString($secret, $line);
            }
        }
    }

    /**
     * CARRIES WEIGHT. A failed email delivery is indistinguishable from a
     * successful one, byte for byte.
     *
     * THIS IS THE ORACLE TEST. A real SMTP transport rejects an invalid,
     * unroutable or suppressed recipient synchronously; if that became a `500`
     * while a deliverable address got `202`, the endpoint would answer "does
     * this mailbox exist?" and — because suppression lists are built from past
     * bounces — partly "has this address been used here before?". Two
     * registrations, one whose delivery succeeds and one whose delivery is made
     * to fail, are compared on both things a stranger can read: the status, and
     * the response body in full rather than field by field — an added key would
     * otherwise slip past.
     */
    public function test_a_failed_email_delivery_is_indistinguishable_from_a_delivered_one(): void
    {
        $delivered = $this->send($this->start(), 'email', self::EMAIL);

        $this->email->fail();
        $rejected = $this->send($this->start(), 'email', self::OTHER_EMAIL);

        $delivered->assertStatus(202);
        $rejected->assertStatus(202);
        self::assertSame(
            (string) $delivered->getContent(),
            (string) $rejected->getContent(),
            'the response distinguishes a rejected recipient from a deliverable one',
        );

        $body = (string) $rejected->getContent();
        self::assertStringNotContainsString(self::OTHER_EMAIL, $body);
        self::assertStringNotContainsString('error', $body);

        // The challenge committed before delivery was attempted, so the
        // cooldown applies and provoking a failure is not a way around it.
        self::assertSame(2, OtpChallenge::query()->count());
    }

    /**
     * CARRIES WEIGHT. `202` is acceptance of the REQUEST and nothing more.
     *
     * A passcode nobody received still proves nothing: verification wants the
     * actual code. The accepted response must not be mistakable for a step
     * completed, so the registration that got it is still unproven and the
     * codes the endpoint never delivered are still the only ones that work.
     */
    public function test_an_accepted_request_whose_delivery_failed_still_proves_nothing(): void
    {
        $credential = $this->start();
        $this->email->fail();

        $this->send($credential, 'email', self::EMAIL)->assertStatus(202);

        $registration = Registration::query()->sole();
        self::assertSame(self::EMAIL, $registration->email, 'the destination was not bound');
        self::assertNull($registration->email_verified_at, 'acceptance counted as proof');

        // The code exists on the row; it simply never left the building. A
        // caller that did not receive it cannot guess it.
        $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => 'email',
            'code' => '000000',
        ])->assertStatus(401);

        self::assertNull(
            Registration::query()->sole()->email_verified_at,
            'a wrong code proved the channel',
        );
    }

    /**
     * A failed SMS delivery is still a `500`, and deliberately so.
     *
     * No SMS provider has been selected, so that sender refuses every send
     * identically and distinguishes no recipient — there is no oracle to close.
     * Asserted so the email normalization above cannot quietly be read as a
     * blanket "swallow every delivery failure".
     */
    public function test_a_failed_sms_delivery_is_still_a_plain_internal_error(): void
    {
        $this->sms->fail();

        $response = $this->send($this->start(), 'sms', self::PHONE);

        $response->assertStatus(500);
        $response->assertJsonPath('error.code', ApiError::INTERNAL_ERROR);
        self::assertStringNotContainsString(self::PHONE, (string) $response->getContent());
    }

    /**
     * Malformed input is still an ordinary validation error.
     *
     * @param  array<string, mixed>  $payload
     */
    #[DataProvider('malformedSends')]
    public function test_malformed_input_is_a_validation_error(array $payload, string $field): void
    {
        $response = $this->postJson(
            self::SEND,
            ['registration_credential' => $this->start()] + $payload,
        );

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ApiError::VALIDATION_FAILED);
        $response->assertJsonStructure(['error' => ['details' => [$field]]]);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedSends(): array
    {
        return [
            'no channel' => [['destination' => 'member@ridemate.invalid'], 'channel'],
            'an invented channel' => [
                ['channel' => 'carrier_pigeon', 'destination' => 'member@ridemate.invalid'],
                'channel',
            ],
            'not an address' => [['channel' => 'email', 'destination' => 'nope'], 'destination'],
            'not a number' => [['channel' => 'sms', 'destination' => '+90999999999'], 'destination'],
            'an address on the SMS channel' => [
                ['channel' => 'sms', 'destination' => 'member@ridemate.invalid'],
                'destination',
            ],
        ];
    }

    // --------------------------------------------------------------- helpers

    private function start(): string
    {
        $response = $this->postJson(self::START);
        $response->assertStatus(201);

        return (string) $response->json('registration_credential');
    }

    /**
     * @return TestResponse<JsonResponse>
     */
    private function send(string $credential, string $channel, string $destination)
    {
        return $this->postJson(self::SEND, [
            'registration_credential' => $credential,
            'channel' => $channel,
            'destination' => $destination,
        ]);
    }

    /** Sends and verifies one channel, entirely over HTTP. */
    private function prove(string $credential, string $channel, ?string $destination = null): void
    {
        $destination ??= $channel === 'email' ? self::EMAIL : self::PHONE;

        $this->send($credential, $channel, $destination)->assertStatus(202);

        $code = $channel === 'email' ? $this->lastEmailCode() : (string) $this->sms->lastCode();

        $this->postJson(self::VERIFY, [
            'registration_credential' => $credential,
            'channel' => $channel,
            'code' => $code,
        ])->assertStatus(204);
    }

    /** A registration that has proven both channels. Returns its credential. */
    private function fullyProven(string $email = self::EMAIL, string $phone = self::PHONE): string
    {
        $credential = $this->start();

        $this->prove($credential, 'email', $email);

        // Past the destination-wide cooldown, which is keyed on the
        // destination rather than the channel pair being proven.
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addSeconds(120));

        $this->prove($credential, 'sms', $phone);

        return $credential;
    }

    private function lastEmailCode(): string
    {
        $code = $this->email->lastCode();
        self::assertNotNull($code, 'the email sender should have received a passcode');

        return $code;
    }

    /**
     * The body with the correlation id removed, so two responses can be
     * compared for whether they say the same thing.
     *
     * @param  TestResponse<JsonResponse>  $response
     * @return array<string, mixed>
     */
    private function withoutRequestId($response): array
    {
        /** @var array{error: array<string, mixed>} $body */
        $body = $response->json();

        unset($body['error']['request_id']);

        return $body;
    }

    /**
     * Builds one of the unusable credentials without touching the database for
     * the cases that do not need it.
     */
    private function credentialFor(string $case): string
    {
        return match ($case) {
            'garbage' => 'garbage',
            'access' => 'rma_00000000-0000-7000-8000-000000000000.NOTASECRET',
            'unknown' => 'rmreg_00000000-0000-7000-8000-000000000000.NOTASECRET',
            'wrong-secret' => (function (): string {
                $credential = $this->start();
                $id = Registration::query()->firstOrFail()->id;

                self::assertStringContainsString($id, $credential);

                return 'rmreg_'.$id.'.NOTTHESECRET';
            })(),
            default => throw new \LogicException("unknown case $case"),
        };
    }
}
