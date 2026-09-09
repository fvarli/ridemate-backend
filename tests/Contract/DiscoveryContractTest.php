<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * The discovery contract, and what it must refuse.
 *
 * This is the representation a stranger reads about another member, so what the
 * document does NOT admit matters more than what it does. A schema that accepts
 * anything passes every positive assertion ever written against it.
 */
final class DiscoveryContractTest extends TestCase
{
    use ValidatesTheContract;

    private const PATH = '/api/v1/routes/discover';

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
    private function discovered(): array
    {
        return [
            'id' => '01991b00-0000-7000-8000-0000000000a1',
            'origin' => ['id' => '01991a00-0000-7000-8000-000000000001', 'label' => 'Kadıköy'],
            'destination' => ['id' => '01991a00-0000-7000-8000-000000000002', 'label' => 'Levent'],
            'recurrence' => 'weekdays',
            'departure_date' => null,
            'departure_time' => '08:25',
            'timezone' => 'Europe/Istanbul',
            'departure_state' => 'upcoming',
            'seats_offered' => 3,
            'rules' => [
                'no_smoking' => true,
                'music_ok' => false,
                'no_pets' => false,
                'quiet' => false,
            ],
            'driver' => ['display_name' => 'İrem Yılmaz', 'initials' => 'İY'],
            // Present on every result from Phase 13, and null for the common
            // case: the caller has not asked about this journey.
            'my_seat_request' => null,
        ];
    }

    // ------------------------------------------------------------- documented

    public function test_the_operation_is_documented_and_authenticated(): void
    {
        /** @var array<string, mixed> $paths */
        $paths = self::contractDocument()['paths'];

        self::assertArrayHasKey(self::PATH, $paths);
        self::assertSame(['get'], array_keys($paths[self::PATH]));
        self::assertSame([['bearerAuth' => []]], $paths[self::PATH]['get']['security']);
        self::assertSame(
            [200, 401, 403, 422],
            array_keys($paths[self::PATH]['get']['responses']),
        );
    }

    /**
     * CARRIES WEIGHT. Exactly four query parameters, and no more.
     *
     * Seats, sort, date, radius and preferences are things the Search screen
     * collects and this query cannot answer. Documenting one would promise it.
     */
    public function test_the_request_takes_exactly_four_parameters(): void
    {
        /** @var list<array<string, mixed>> $parameters */
        $parameters = self::contractDocument()['paths'][self::PATH]['get']['parameters'];

        $names = [];
        foreach ($parameters as $parameter) {
            /** @var array{'$ref'?: string} $parameter */
            $ref = $parameter['$ref'] ?? '';
            $names[] = substr((string) $ref, strrpos((string) $ref, '/') + 1);
        }

        self::assertSame(
            ['OriginPlaceId', 'DestinationPlaceId', 'Cursor', 'Limit'],
            $names,
        );
    }

    // ----------------------------------------------------------------- shapes

    public function test_a_result_validates(): void
    {
        $this->assertValidates($this->discovered(), 'DiscoveredRoute');
        $this->assertValidates(
            ['routes' => [$this->discovered()], 'next_cursor' => null],
            'DiscoveryPage',
        );
    }

    public function test_a_one_off_result_carries_its_date(): void
    {
        $this->assertValidates(
            ['recurrence' => 'once', 'departure_date' => '2026-09-14'] + $this->discovered(),
            'DiscoveredRoute',
        );
    }

    /**
     * CARRIES WEIGHT. Every field a stranger must never be shown, refused one
     * at a time so a failure names which crept in.
     */
    public function test_no_internal_or_invented_field_is_admitted(): void
    {
        foreach ([
            'account_id' => '00000000-0000-7000-8000-000000000001',
            'profile_id' => '00000000-0000-7000-8000-000000000002',
            'status' => 'published',
            'published_at' => '2026-09-08T09:41:00+00:00',
            'created_at' => '2026-09-08T09:41:00+00:00',
            'cancelled_at' => null,
            'seats_available' => 2,
            'walk_minutes' => 6,
            'compatibility' => 0.94,
            'cost_share_per_person' => 18,
            'trip_minutes' => 35,
        ] as $key => $value) {
            $this->assertRejects(
                [$key => $value] + $this->discovered(),
                'DiscoveredRoute',
                "the contract admitted `$key`, which no discovery result may carry",
            );
        }
    }

    /**
     * CARRIES WEIGHT. The driver is two fields.
     */
    public function test_the_driver_admits_only_a_name_and_initials(): void
    {
        /** @var array<string, mixed> $schema */
        $schema = self::contractDocument()['components']['schemas']['DiscoveredDriver'];

        self::assertSame(['display_name', 'initials'], array_keys($schema['properties']));
        self::assertSame(['display_name', 'initials'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);

        foreach ([
            'id' => '00000000-0000-7000-8000-000000000001',
            'account_id' => '00000000-0000-7000-8000-000000000002',
            'phone_e164' => '+905321234567',
            'rating' => 4.9,
            'is_verified' => true,
            'trip_count' => 73,
            'trust_score' => 92,
            'approval_rate' => 0.97,
            'member_since' => '2024',
        ] as $key => $value) {
            $this->assertRejects(
                ['driver' => [$key => $value] + ['display_name' => 'İrem', 'initials' => 'İ']]
                    + $this->discovered(),
                'DiscoveredRoute',
                "the contract admitted a driver `$key`, which the product does not have",
            );
        }
    }

    public function test_the_page_admits_nothing_beside_its_two_fields(): void
    {
        $this->assertRejects(
            ['routes' => [], 'next_cursor' => null, 'total' => 0],
            'DiscoveryPage',
            'the page admitted a count, which a keyset list does not have',
        );
    }

    // ------------------------------------------------------------- vocabulary

    public function test_the_discovery_schemas_name_nothing_that_does_not_exist(): void
    {
        /** @var array<string, mixed> $schemas */
        $schemas = self::contractDocument()['components']['schemas'];

        $encoded = json_encode([
            $schemas['DiscoveryPage'],
            $schemas['DiscoveredRoute'],
            $schemas['DiscoveredDriver'],
        ]);
        self::assertIsString($encoded);

        foreach ([
            '"rating', '"trust', '"verified', '"is_verified', '"trip_count',
            '"approval', '"compatibility', '"walk', '"savings', '"fare',
            '"price', '"cost', '"seats_available', '"available_seats',
            '"latitude', '"longitude', '"radius', '"distance',
        ] as $absent) {
            self::assertStringNotContainsString(
                $absent,
                $encoded,
                "the discovery schemas name `$absent`, which the product does not have",
            );
        }
    }
}
