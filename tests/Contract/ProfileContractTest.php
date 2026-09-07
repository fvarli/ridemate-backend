<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * The profile contract, and what it must refuse.
 *
 * A schema that accepts anything passes every positive assertion ever written
 * against it, so the weight here is negative: the payloads that must NOT
 * validate. For a profile that matters more than usual, because this is the one
 * representation another member will eventually see — the document is where
 * "two fields and no more" is actually enforced, and `additionalProperties:
 * false` is only worth writing if something proves it bites.
 */
final class ProfileContractTest extends TestCase
{
    use ValidatesTheContract;

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertValidates(array $payload, string $schema): void
    {
        $this->assertMatchesSchema(
            TestResponse::fromBaseResponse(new JsonResponse($payload)),
            $schema,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertRejects(array $payload, string $schema, string $why): void
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

    /**
     * @return array<string, mixed>
     */
    private function envelope(): array
    {
        return ['profile' => ['display_name' => 'İrem Yılmaz', 'initials' => 'İY']];
    }

    // ------------------------------------------------------------- documented

    public function test_both_profile_operations_are_documented(): void
    {
        /** @var array<string, mixed> $paths */
        $paths = self::contractDocument()['paths'];

        self::assertArrayHasKey('/api/v1/me/profile', $paths);
        self::assertSame(['get', 'put'], array_keys($paths['/api/v1/me/profile']));
    }

    /**
     * Deletion is not documented, because it is not a capability. A contract
     * describing one would be describing an intention nobody has.
     */
    public function test_no_profile_deletion_is_documented(): void
    {
        /** @var array<string, mixed> $paths */
        $paths = self::contractDocument()['paths'];

        self::assertArrayNotHasKey('delete', $paths['/api/v1/me/profile']);
    }

    public function test_both_operations_require_a_credential(): void
    {
        /** @var array<string, mixed> $operations */
        $operations = self::contractDocument()['paths']['/api/v1/me/profile'];

        foreach (['get', 'put'] as $method) {
            self::assertSame([['bearerAuth' => []]], $operations[$method]['security']);
        }
    }

    /**
     * Both success codes are published, because the client routes on the
     * difference between them.
     */
    public function test_the_write_documents_both_created_and_updated(): void
    {
        /** @var array<string, mixed> $put */
        $put = self::contractDocument()['paths']['/api/v1/me/profile']['put'];

        self::assertSame([200, 201, 401, 403, 422], array_keys($put['responses']));
    }

    public function test_the_read_documents_a_definitive_404(): void
    {
        /** @var array<string, mixed> $get */
        $get = self::contractDocument()['paths']['/api/v1/me/profile']['get'];

        self::assertSame([200, 401, 403, 404], array_keys($get['responses']));
    }

    /**
     * No Idempotency-Key parameter, because the operation is idempotent by its
     * shape. Documenting a header the server ignores would be worse than not
     * having one.
     */
    public function test_the_write_declares_no_idempotency_key(): void
    {
        /** @var array<string, mixed> $put */
        $put = self::contractDocument()['paths']['/api/v1/me/profile']['put'];

        self::assertArrayNotHasKey('parameters', $put);
    }

    // ----------------------------------------------------------------- shapes

    public function test_the_envelope_validates(): void
    {
        $this->assertValidates($this->envelope(), 'ProfileEnvelope');
    }

    /**
     * CARRIES WEIGHT. Exactly two fields, and the document is what says so.
     */
    public function test_the_profile_admits_only_display_name_and_initials(): void
    {
        /** @var array<string, mixed> $schema */
        $schema = self::contractDocument()['components']['schemas']['Profile'];

        self::assertSame(['display_name', 'initials'], array_keys($schema['properties']));
        self::assertSame(['display_name', 'initials'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
    }

    /**
     * The fields somebody would reach for first, refused one at a time so a
     * failure names which one crept in.
     *
     * `id` and `account_id` are internal; the timestamps describe a row rather
     * than a member; `phone_e164` belongs to the credential and to no
     * representation anyone else sees; the rest name phases that do not exist.
     */
    public function test_no_internal_or_invented_field_is_admitted(): void
    {
        foreach ([
            'id' => '00000000-0000-7000-8000-000000000001',
            'account_id' => '00000000-0000-7000-8000-000000000002',
            'created_at' => '2026-09-07T09:41:00+00:00',
            'updated_at' => '2026-09-07T09:41:00+00:00',
            'phone_e164' => '+905321234567',
            'status' => 'active',
            'avatar_url' => 'https://example.test/a.png',
            'bio' => 'merhaba',
            'rating' => 4.9,
            'trip_count' => 73,
            'trust_score' => 92,
            'is_verified' => true,
        ] as $key => $value) {
            $this->assertRejects(
                ['profile' => ['display_name' => 'İrem Yılmaz', 'initials' => 'İY', $key => $value]],
                'ProfileEnvelope',
                "the contract admitted `$key`, which no profile representation may carry",
            );
        }
    }

    public function test_the_envelope_admits_nothing_beside_the_profile(): void
    {
        $this->assertRejects(
            $this->envelope() + ['account' => ['id' => '00000000-0000-7000-8000-000000000001']],
            'ProfileEnvelope',
            'the envelope admitted a sibling of `profile`',
        );
    }

    public function test_both_fields_are_required(): void
    {
        $this->assertRejects(
            ['profile' => ['display_name' => 'İrem Yılmaz']],
            'ProfileEnvelope',
            'a profile without initials validated',
        );

        $this->assertRejects(
            ['profile' => ['initials' => 'İY']],
            'ProfileEnvelope',
            'a profile without a display name validated',
        );
    }

    // ---------------------------------------------------------------- lengths

    public function test_the_published_name_bounds_are_enforced(): void
    {
        $this->assertValidates(
            ['profile' => ['display_name' => 'A', 'initials' => 'A']],
            'ProfileEnvelope',
        );

        $this->assertValidates(
            ['profile' => ['display_name' => str_repeat('ş', 80), 'initials' => 'ŞŞ']],
            'ProfileEnvelope',
        );

        $this->assertRejects(
            ['profile' => ['display_name' => '', 'initials' => 'A']],
            'ProfileEnvelope',
            'an empty display name validated',
        );

        $this->assertRejects(
            ['profile' => ['display_name' => str_repeat('a', 81), 'initials' => 'AA']],
            'ProfileEnvelope',
            'an 81-character display name validated',
        );
    }

    // ----------------------------------------------------------------- update

    public function test_the_update_takes_one_field(): void
    {
        $this->assertValidates(['display_name' => 'İrem Yılmaz'], 'ProfileUpdate');
    }

    /**
     * CARRIES WEIGHT. `initials` is derived, so a client may not send one — a
     * profile whose initials disagreed with its own name would be a small,
     * permanent, visible lie.
     */
    public function test_the_update_refuses_initials(): void
    {
        $this->assertRejects(
            ['display_name' => 'İrem Yılmaz', 'initials' => 'ZZ'],
            'ProfileUpdate',
            'the update admitted client-supplied initials',
        );
    }

    public function test_the_update_refuses_an_owner(): void
    {
        foreach (['account_id', 'id'] as $key) {
            $this->assertRejects(
                ['display_name' => 'İrem Yılmaz', $key => '00000000-0000-7000-8000-000000000001'],
                'ProfileUpdate',
                "the update admitted `$key`, which would let a request name an owner",
            );
        }
    }

    public function test_the_update_requires_a_display_name(): void
    {
        $this->assertRejects([], 'ProfileUpdate', 'an empty update validated');
    }

    public function test_the_update_enforces_the_same_bounds(): void
    {
        $this->assertRejects(
            ['display_name' => ''],
            'ProfileUpdate',
            'an empty display name validated',
        );

        $this->assertRejects(
            ['display_name' => str_repeat('a', 81)],
            'ProfileUpdate',
            'an 81-character display name validated',
        );
    }

    // ------------------------------------------------------------- vocabulary

    /**
     * Nothing the product does not have appears anywhere in the profile
     * section — including cost, which no RideMate surface may ever carry.
     */
    public function test_the_profile_schemas_name_nothing_that_does_not_exist(): void
    {
        /** @var array<string, mixed> $schemas */
        $schemas = self::contractDocument()['components']['schemas'];

        $encoded = json_encode([
            $schemas['Profile'],
            $schemas['ProfileEnvelope'],
            $schemas['ProfileUpdate'],
        ]);
        self::assertIsString($encoded);

        foreach ([
            'avatar', 'bio', 'rating', 'review', 'trip_count', 'trust',
            'verified', 'badge', 'savings', 'fare', 'price', 'cost',
        ] as $absent) {
            self::assertStringNotContainsString(
                '"'.$absent,
                $encoded,
                "the profile schemas name `$absent`, which the product does not have",
            );
        }
    }
}
