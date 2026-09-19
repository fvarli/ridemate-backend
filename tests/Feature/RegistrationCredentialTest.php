<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\OtpChallenge;
use App\Models\Registration;
use App\Registration\RegistrationSecret;
use App\Registration\RegistrationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * The pre-account registration credential: what it opens, and the four things
 * it must never be.
 *
 * Two properties carry this file.
 *
 * The first is that the credential is a lookup key for one registration and
 * nothing else. It authenticates nobody, opens no session, and is refused by
 * the auth path by construction rather than by a check somebody has to
 * remember. The counts at the end of this file are what would notice if that
 * ever stopped being true.
 *
 * The second is that presenting it again is ORDINARY. This is deliberately not
 * a refresh token: a registration takes several requests, the same credential
 * carries all of them, and a second use is continuation rather than theft.
 * Copying the rotation rule here would sign members out of a flow they were
 * halfway through.
 */
final class RegistrationCredentialTest extends TestCase
{
    use RefreshDatabase;

    private RegistrationService $registrations;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registrations = app(RegistrationService::class);
    }

    // --------------------------------------------------------------- minting

    public function test_starting_writes_a_registration_and_returns_an_opaque_credential(): void
    {
        $minted = $this->registrations->start();

        self::assertTrue(Str::isUuid($minted->registrationId));
        self::assertStringStartsWith('rmreg_', $minted->credential);
        self::assertTrue($minted->expiresAt->isFuture());

        $registration = Registration::query()->findOrFail($minted->registrationId);
        self::assertNull($registration->email);
        self::assertNull($registration->phone_e164);
        self::assertNull($registration->email_verified_at);
        self::assertNull($registration->phone_verified_at);
        self::assertNull($registration->completed_at);
        self::assertTrue($registration->isAdvanceable());
        self::assertFalse($registration->isFullyProven());
    }

    /**
     * The id in the credential is the id of the row, so resolution is a
     * primary-key read rather than a search over a 64-character column.
     */
    public function test_the_credential_carries_the_registration_id(): void
    {
        $minted = $this->registrations->start();

        $parsed = RegistrationSecret::parse($minted->credential);

        self::assertNotNull($parsed);
        self::assertSame($minted->registrationId, $parsed->registrationId);
    }

    /**
     * CARRIES WEIGHT. The secret exists in the response and nowhere else.
     *
     * Asserted against every column of the row rather than against
     * `credential_hash` alone: a plaintext copy that landed in some other
     * column would pass the narrower check.
     */
    public function test_the_plaintext_credential_is_never_persisted(): void
    {
        $minted = $this->registrations->start();

        $parsed = RegistrationSecret::parse($minted->credential);
        self::assertNotNull($parsed);

        /** @var array<string, mixed> $row */
        $row = (array) DB::table('registrations')->where('id', $minted->registrationId)->first();

        foreach ($row as $column => $value) {
            if (is_string($value)) {
                self::assertStringNotContainsString($parsed->secret, $value, $column);
                self::assertStringNotContainsString($minted->credential, $value, $column);
            }
        }

        self::assertSame(RegistrationSecret::hash($parsed->secret), $row['credential_hash']);
    }

    /**
     * The expiry is the registration's, and it comes from configuration rather
     * than from a number remembered at a call site.
     */
    public function test_the_lifetime_comes_from_configuration(): void
    {
        config(['ridemate.registration.ttl' => 120]);

        $before = CarbonImmutable::now();
        $minted = $this->registrations->start();

        self::assertEqualsWithDelta(
            $before->addSeconds(120)->getTimestamp(),
            $minted->expiresAt->getTimestamp(),
            2,
        );
    }

    /**
     * Two registrations are two secrets. Trivially true of `random_bytes`, and
     * worth one assertion because the failure mode — a constant, a seeded
     * generator, a copied hash — is silent and total.
     */
    public function test_each_registration_gets_its_own_secret(): void
    {
        $first = $this->registrations->start();
        $second = $this->registrations->start();

        self::assertNotSame($first->credential, $second->credential);
        self::assertNotSame(
            Registration::query()->findOrFail($first->registrationId)->credential_hash,
            Registration::query()->findOrFail($second->registrationId)->credential_hash,
        );
    }

    // ------------------------------------------------------------- resolving

    public function test_a_valid_credential_resolves_its_own_registration(): void
    {
        $mine = $this->registrations->start();
        $theirs = $this->registrations->start();

        $resolved = $this->registrations->resolve($mine->credential);

        self::assertNotNull($resolved);
        self::assertSame($mine->registrationId, $resolved->id);
        self::assertNotSame($theirs->registrationId, $resolved->id);
    }

    /**
     * CARRIES WEIGHT. Presenting it again is continuation, not reuse.
     *
     * The opposite of the refresh-token rule on purpose: a registration spans
     * at least three requests and one credential carries all of them. Nothing
     * is rotated, nothing is revoked, and the registration is untouched.
     */
    public function test_a_credential_may_be_used_repeatedly_while_the_registration_lives(): void
    {
        $minted = $this->registrations->start();

        for ($i = 0; $i < 5; $i++) {
            self::assertNotNull($this->registrations->resolve($minted->credential));
        }

        $registration = Registration::query()->findOrFail($minted->registrationId);
        self::assertTrue($registration->isAdvanceable());
        self::assertSame(1, Registration::query()->count());
    }

    /**
     * CARRIES WEIGHT. Every refusal is the same refusal.
     *
     * Malformed, unknown, the wrong secret, expired and completed are five
     * different facts about a credential somebody does not hold, and telling
     * them apart is how a caller learns whether a registration ever existed or
     * how it ended. The service cannot distinguish them for a caller because it
     * returns one value, which is the cheapest guarantee that it never will.
     */
    #[DataProvider('refusedCredentials')]
    public function test_every_unusable_credential_refuses_identically(string $case): void
    {
        self::assertNull($this->registrations->resolve($this->credentialFor($case)));
    }

    /** @return iterable<string, array{string}> */
    public static function refusedCredentials(): iterable
    {
        yield 'empty' => ['empty'];
        yield 'no prefix' => ['no-prefix'];
        yield 'an access token prefix' => ['access-prefix'];
        yield 'a refresh token prefix' => ['refresh-prefix'];
        yield 'no separator' => ['no-separator'];
        yield 'two separators' => ['two-separators'];
        yield 'an id that is not a uuid' => ['bad-id'];
        yield 'an empty secret' => ['empty-secret'];
        yield 'a secret outside the alphabet' => ['bad-secret'];
        yield 'a well-formed unknown credential' => ['unknown'];
        yield 'the right registration and the wrong secret' => ['wrong-secret'];
        yield 'an expired registration' => ['expired'];
        yield 'a completed registration' => ['completed'];
    }

    /**
     * CARRIES WEIGHT. A malformed id never becomes a bound parameter.
     *
     * Parsing refuses before the database is touched, so caller-supplied text
     * cannot reach a driver error and from there a log line or a 500 body.
     * Asserted by counting queries rather than by reading the answer, because
     * the answer is the same either way.
     */
    public function test_a_malformed_credential_is_refused_before_any_query(): void
    {
        $queries = 0;
        DB::listen(function () use (&$queries): void {
            $queries++;
        });

        self::assertNull($this->registrations->resolve('rmreg_not-a-uuid.secret'));
        self::assertNull($this->registrations->resolve('definitely not a credential'));

        self::assertSame(0, $queries);
    }

    /**
     * A registration that has ended stays ended. Nothing about resolving an
     * expired or completed one revives it, and nothing is written.
     */
    public function test_refusing_an_ended_registration_changes_nothing(): void
    {
        $minted = $this->registrations->start();
        $this->complete($minted->registrationId);

        self::assertNull($this->registrations->resolve($minted->credential));

        $registration = Registration::query()->findOrFail($minted->registrationId);
        self::assertNotNull($registration->completed_at);
        self::assertFalse($registration->isAdvanceable());
    }

    // ------------------------------------------------- what it is not

    /**
     * CARRIES WEIGHT, AND IS THE POINT OF THE WHOLE SLICE.
     *
     * A registration credential is not an authentication credential. Minting
     * and resolving one creates no account, opens no session and issues no
     * token — and it issues no passcode either, because proof attachment is a
     * later slice and must not have arrived quietly in this one.
     */
    public function test_registration_mechanics_create_no_account_session_or_token(): void
    {
        $minted = $this->registrations->start();
        $this->registrations->resolve($minted->credential);

        self::assertSame(0, Account::query()->count(), 'a registration created an account');
        self::assertSame(0, DB::table('auth_sessions')->count(), 'a registration opened a session');
        self::assertSame(0, DB::table('auth_tokens')->count(), 'a registration issued a token');
        self::assertSame(0, OtpChallenge::query()->count(), 'a registration issued a passcode');
    }

    /**
     * CARRIES WEIGHT. The hash domains are the real separation.
     *
     * A prefix check can be deleted; a digest computed under a different domain
     * cannot be made to match. So a registration secret hashed as an access or
     * refresh token produces a different string, which is what makes the two
     * credential families structurally unable to validate as one another.
     */
    public function test_the_credential_hashes_under_its_own_domain(): void
    {
        $secret = RegistrationSecret::generate();

        self::assertNotSame(hash('sha256', 'rm.access.v1:'.$secret), RegistrationSecret::hash($secret));
        self::assertNotSame(hash('sha256', 'rm.refresh.v1:'.$secret), RegistrationSecret::hash($secret));
        self::assertSame(hash('sha256', 'rm.registration.v1:'.$secret), RegistrationSecret::hash($secret));
    }

    /**
     * CARRIES WEIGHT. Nothing public reaches any of this.
     *
     * There is no registration API after this slice, so a route resolving any
     * of these classes would mean one arrived without a contract.
     */
    public function test_no_route_resolves_the_registration_capability(): void
    {
        $internal = [
            RegistrationService::class,
            RegistrationSecret::class,
            Registration::class,
        ];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach ($internal as $class) {
                self::assertStringNotContainsString(
                    $class,
                    $route->getActionName(),
                    'a route resolves the internal registration capability',
                );
            }

            self::assertStringNotContainsString(
                'registration',
                $route->uri(),
                'a public registration route exists',
            );
        }
    }

    // --------------------------------------------------------------- helpers

    /**
     * Builds one of the refused credentials, so the data provider can name
     * cases without constructing rows before the application boots.
     */
    private function credentialFor(string $case): string
    {
        $uuid = (string) Str::uuid7();

        return match ($case) {
            'empty' => '',
            'no-prefix' => $uuid.'.'.RegistrationSecret::generate(),
            'access-prefix' => 'rma_'.$uuid.'.'.RegistrationSecret::generate(),
            'refresh-prefix' => 'rmr_'.$uuid.'.'.RegistrationSecret::generate(),
            'no-separator' => 'rmreg_'.$uuid.RegistrationSecret::generate(),
            'two-separators' => 'rmreg_'.$uuid.'.'.RegistrationSecret::generate().'.x',
            'bad-id' => 'rmreg_not-a-uuid.'.RegistrationSecret::generate(),
            'empty-secret' => 'rmreg_'.$uuid.'.',
            'bad-secret' => 'rmreg_'.$uuid.'.has spaces',
            'unknown' => RegistrationSecret::compose($uuid, RegistrationSecret::generate()),
            'wrong-secret' => RegistrationSecret::compose(
                $this->registrations->start()->registrationId,
                RegistrationSecret::generate(),
            ),
            'expired' => $this->expired(),
            'completed' => $this->completed(),
            default => throw new LogicException("unknown case $case"),
        };
    }

    private function expired(): string
    {
        $minted = $this->registrations->start();

        DB::table('registrations')
            ->where('id', $minted->registrationId)
            ->update(['expires_at' => CarbonImmutable::now()->subSecond()]);

        return $minted->credential;
    }

    private function completed(): string
    {
        $minted = $this->registrations->start();
        $this->complete($minted->registrationId);

        return $minted->credential;
    }

    /**
     * Written directly, because completion does not exist yet.
     *
     * This slice implements no path that sets `completed_at`; the column is
     * here so the refusal it causes can be proven before the slice that writes
     * it arrives.
     */
    private function complete(string $registrationId): void
    {
        DB::table('registrations')
            ->where('id', $registrationId)
            ->update(['completed_at' => CarbonImmutable::now()]);
    }
}
