<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Models\Profile;
use App\Support\ApiError;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Contract\ValidatesTheContract;
use Tests\Support\CleansCommittedRows;
use Tests\Support\CreatesAccounts;
use Tests\Support\InteractsWithAuthEndpoints;
use Tests\TestCase;

/**
 * `GET` and `PUT /api/v1/me/profile` over real HTTP.
 *
 * Two things carry the weight here. The first is the response's exact key set:
 * a profile is the one representation another member will eventually see, so
 * what it does NOT contain matters more than what it does. The second is that a
 * missing profile is a 404 rather than an empty success — the client routes on
 * that difference, and a client that had to inspect fields to tell the states
 * apart would eventually read a failed decode as "no profile".
 */
final class ProfileEndpointTest extends TestCase
{
    use CleansCommittedRows;
    use CreatesAccounts;
    use DatabaseTruncation;
    use InteractsWithAuthEndpoints;
    use ValidatesTheContract;

    /** @var list<string> */
    protected array $tablesToTruncate = ['profiles', 'accounts', 'auth_sessions', 'auth_tokens', 'otp_challenges'];

    private const PATH = '/api/v1/me/profile';

    private const PHONE = '+905321234567';

    protected function setUp(): void
    {
        parent::setUp();
        $this->bindTestSmsSender();
    }

    protected function tearDown(): void
    {
        $this->truncateCommittedAuthRows();

        parent::tearDown();
    }

    /** @return array<string, string> */
    private function credential(): array
    {
        return $this->bearer($this->signIn(self::PHONE)['access_token']);
    }

    // ------------------------------------------------------------------ read

    /**
     * CARRIES WEIGHT. A profile that does not exist is a 404, not an empty 200.
     */
    public function test_a_member_without_a_profile_gets_a_definitive_404(): void
    {
        $response = $this->getJson(self::PATH, $this->credential());

        $response->assertStatus(404);
        $response->assertJsonPath('error.code', ApiError::NOT_FOUND);
        $response->assertHeader('X-Request-Id');
    }

    /**
     * And it is distinguishable from failing to authenticate, which is the
     * whole point: one means "sign in", the other means "choose a name".
     */
    public function test_a_missing_profile_and_a_missing_credential_answer_differently(): void
    {
        $this->getJson(self::PATH)->assertStatus(401);
        $this->getJson(self::PATH, $this->credential())->assertStatus(404);
    }

    public function test_a_bad_credential_is_refused_before_the_profile_is_considered(): void
    {
        $this->createAccount(self::PHONE);

        $this->getJson(self::PATH, $this->bearer('not-a-real-token'))->assertStatus(401);
    }

    public function test_a_profile_is_returned_once_it_exists(): void
    {
        $credential = $this->credential();
        $this->putJson(self::PATH, ['display_name' => 'Ayşe Demir'], $credential)->assertStatus(201);

        $response = $this->getJson(self::PATH, $credential);

        $response->assertStatus(200);
        $response->assertExactJson([
            'profile' => ['display_name' => 'Ayşe Demir', 'initials' => 'AD'],
        ]);
        $response->assertHeader('X-Request-Id');
    }

    // ----------------------------------------------------------------- write

    /**
     * CARRIES WEIGHT. 201 then 200 — the distinction the client routes on.
     */
    public function test_the_first_save_creates_and_the_second_updates(): void
    {
        $credential = $this->credential();

        $created = $this->putJson(self::PATH, ['display_name' => 'Ayşe Demir'], $credential);
        $created->assertStatus(201);
        $created->assertExactJson([
            'profile' => ['display_name' => 'Ayşe Demir', 'initials' => 'AD'],
        ]);

        $updated = $this->putJson(self::PATH, ['display_name' => 'Ayşe Yılmaz'], $credential);
        $updated->assertStatus(200);
        $updated->assertExactJson([
            'profile' => ['display_name' => 'Ayşe Yılmaz', 'initials' => 'AY'],
        ]);

        self::assertSame(1, Profile::query()->count());
    }

    /**
     * The target-state property that lets this endpoint go without an
     * Idempotency-Key: repeating the same body changes nothing and adds nothing.
     */
    public function test_repeating_the_same_target_state_creates_no_second_row(): void
    {
        $credential = $this->credential();
        $body = ['display_name' => 'Ayşe Demir'];

        $this->putJson(self::PATH, $body, $credential)->assertStatus(201);
        $this->putJson(self::PATH, $body, $credential)->assertStatus(200);
        $this->putJson(self::PATH, $body, $credential)->assertStatus(200);

        self::assertSame(1, Profile::query()->count());
        self::assertSame('Ayşe Demir', Profile::query()->sole()->display_name);
    }

    public function test_surrounding_whitespace_is_normalized_in_the_response_and_in_storage(): void
    {
        $response = $this->putJson(
            self::PATH,
            ['display_name' => '   Ayşe Demir   '],
            $this->credential(),
        );

        $response->assertStatus(201);
        $response->assertJsonPath('profile.display_name', 'Ayşe Demir');
        self::assertSame('Ayşe Demir', Profile::query()->sole()->display_name);
    }

    /**
     * CARRIES WEIGHT. The Turkish casing rule, end to end over HTTP.
     *
     * `mb_strtoupper('i')` is `I`. If anything in the stack reached for it,
     * this member's initials would read `IY`.
     */
    public function test_a_turkish_name_keeps_its_dotted_capital(): void
    {
        $response = $this->putJson(
            self::PATH,
            ['display_name' => 'irem yılmaz'],
            $this->credential(),
        );

        $response->assertStatus(201);
        $response->assertJsonPath('profile.initials', 'İY');
    }

    public function test_a_single_token_name_gives_one_initial(): void
    {
        $response = $this->putJson(self::PATH, ['display_name' => 'Ayşe'], $this->credential());

        $response->assertStatus(201);
        $response->assertJsonPath('profile.initials', 'A');
    }

    public function test_writing_requires_a_credential(): void
    {
        $this->putJson(self::PATH, ['display_name' => 'Ayşe Demir'])->assertStatus(401);

        self::assertSame(0, Profile::query()->count());
    }

    // ------------------------------------------------------------ refusals

    /**
     * CARRIES WEIGHT. Unknown keys are refused, not ignored.
     *
     * A client sending one of these and getting a success would keep sending it
     * for ever, believing it worked.
     */
    public function test_unknown_request_keys_are_refused(): void
    {
        $credential = $this->credential();

        foreach ([
            // Derived by the server; accepting it would let a member's initials
            // disagree with their own name.
            'initials' => 'ZZ',
            // Ownership comes from the credential.
            'account_id' => '00000000-0000-7000-8000-000000000001',
            'id' => '00000000-0000-7000-8000-000000000002',
            // Things no phase has built.
            'avatar_url' => 'https://example.test/a.png',
            'trust_score' => 92,
            'rating' => 4.9,
            'bio' => 'merhaba',
        ] as $key => $value) {
            $response = $this->putJson(
                self::PATH,
                ['display_name' => 'Ayşe Demir', $key => $value],
                $credential,
            );

            $response->assertStatus(422);
            $response->assertJsonPath('error.code', ApiError::VALIDATION_FAILED);
            $response->assertJsonPath("error.details.$key.0", 'The '.$key.' field is not accepted.');
        }

        self::assertSame(0, Profile::query()->count());
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function unacceptableNames(): array
    {
        return [
            'empty' => [''],
            'only spaces' => ['   '],
            'only a non-breaking space' => ["\u{00A0}"],
            'eighty one characters' => [str_repeat('a', 81)],
            'not a string' => [42],
            'an array' => [['Ayşe']],
        ];
    }

    /**
     * @param  mixed  $name
     */
    #[DataProvider('unacceptableNames')]
    public function test_an_unacceptable_name_is_a_validation_failure($name): void
    {
        $response = $this->putJson(self::PATH, ['display_name' => $name], $this->credential());

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ApiError::VALIDATION_FAILED);
        $response->assertJsonStructure(['error' => ['details' => ['display_name']]]);
        $response->assertHeader('X-Request-Id');

        self::assertSame(0, Profile::query()->count());
    }

    public function test_a_missing_display_name_is_a_validation_failure(): void
    {
        $response = $this->putJson(self::PATH, [], $this->credential());

        $response->assertStatus(422);
        $response->assertJsonPath('error.code', ApiError::VALIDATION_FAILED);
    }

    /**
     * Exactly at the limit, so the boundary is proved from both sides.
     */
    public function test_eighty_characters_are_accepted(): void
    {
        $name = str_repeat('ş', 80);

        $response = $this->putJson(self::PATH, ['display_name' => $name], $this->credential());

        $response->assertStatus(201);
        $response->assertJsonPath('profile.display_name', $name);
    }

    /**
     * Deletion is not a capability. This is not a 405 by accident — no route
     * answers DELETE on this path, and none should.
     */
    public function test_a_profile_cannot_be_deleted(): void
    {
        $credential = $this->credential();
        $this->putJson(self::PATH, ['display_name' => 'Ayşe Demir'], $credential)->assertStatus(201);

        $this->deleteJson(self::PATH, [], $credential)->assertStatus(405);

        self::assertSame(1, Profile::query()->count());
    }

    // ---------------------------------------------------------- the contract

    /**
     * Every response this endpoint can produce, checked against the document
     * itself rather than against what the controller happens to emit.
     *
     * The schema assertions in ProfileContractTest prove the document is
     * strict; these prove the served responses actually match it. Neither is
     * sufficient alone — a correct document with a drifting controller passes
     * one, and a permissive document with a careful controller passes the other.
     */
    public function test_every_response_matches_the_documented_operation(): void
    {
        $credential = $this->credential();

        $this->assertMatchesOperation(
            $this->getJson(self::PATH, $credential),
            self::PATH,
            'get',
        );

        $this->assertMatchesOperation(
            $this->putJson(self::PATH, ['display_name' => 'Ayşe Demir'], $credential),
            self::PATH,
            'put',
        );

        $this->assertMatchesOperation(
            $this->putJson(self::PATH, ['display_name' => 'Ayşe Yılmaz'], $credential),
            self::PATH,
            'put',
        );

        $this->assertMatchesOperation(
            $this->getJson(self::PATH, $credential),
            self::PATH,
            'get',
        );

        // And the refusals, which are as much a part of the contract.
        $this->assertMatchesOperation(
            $this->putJson(self::PATH, ['display_name' => ''], $credential),
            self::PATH,
            'put',
        );

        $this->assertMatchesOperation($this->getJson(self::PATH), self::PATH, 'get');
    }

    // ------------------------------------------------------------- privacy

    /**
     * CARRIES WEIGHT. An exact key set, not a list of things to avoid.
     *
     * A denylist passes whatever a future migration adds. This asserts the
     * whole shape, so a new column reaches the response only when somebody
     * changes this test on purpose.
     */
    public function test_the_response_carries_exactly_two_fields(): void
    {
        $credential = $this->credential();
        $created = $this->putJson(self::PATH, ['display_name' => 'Ayşe Demir'], $credential);
        $read = $this->getJson(self::PATH, $credential);

        foreach ([$created, $read] as $response) {
            /** @var array{profile: array<string, mixed>} $body */
            $body = $response->json();

            self::assertSame(['profile'], array_keys($body));
            self::assertSame(['display_name', 'initials'], array_keys($body['profile']));
        }
    }

    /**
     * And nothing internal reaches the wire, checked against the raw body so a
     * value cannot hide inside a nested structure.
     */
    public function test_no_internal_or_account_value_appears_in_the_body(): void
    {
        $credential = $this->credential();
        $this->putJson(self::PATH, ['display_name' => 'Ayşe Demir'], $credential)->assertStatus(201);

        $profile = Profile::query()->sole();
        $account = $this->createAccount('+905329876543');
        $body = $this->getJson(self::PATH, $credential)->getContent();
        self::assertIsString($body);

        foreach ([
            'the profile id' => $profile->id,
            'the account id' => $profile->account_id,
            'a phone number' => self::PHONE,
            'another account id' => $account->id,
        ] as $what => $value) {
            self::assertStringNotContainsString($value, $body, "$what leaked");
        }

        foreach ([
            'id', 'account_id', 'created_at', 'updated_at', 'phone', 'phone_e164',
            'status', 'session', 'token', 'otp', 'code', 'verified',
        ] as $key) {
            self::assertStringNotContainsString('"'.$key.'"', $body, "$key leaked");
        }
    }
}
