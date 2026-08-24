<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * The route publication contract, and the things it must refuse.
 *
 * A schema that accepts everything passes every positive test ever written
 * against it, so most of this file is negative: payloads that must NOT
 * validate. The departure rule in particular is only worth documenting if the
 * document rejects the shape the database would reject.
 *
 * ON WHY THE CONDITIONAL IS oneOf AND NOT if/then
 *
 * Two engines validate this contract and they support different keywords.
 * `assertMatchesSchema` runs opis/json-schema, which implements draft 2020-12
 * in full. `assertMatchesOperation` — the one that guards the endpoints
 * themselves — runs league/openapi-psr7-validator, which dispatches an explicit
 * list of twenty-one keywords and silently ignores anything else. `if`, `then`,
 * `dependentRequired` and `const` are not on that list.
 *
 * Written with `if/then`, the one-off rule would look enforced and would not be
 * at the layer that matters. `oneOf` and `enum` are implemented by both.
 */
final class RouteContractTest extends TestCase
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
    private function publication(): array
    {
        return [
            'id' => '01991b00-0000-7000-8000-0000000000a1',
            'origin_place_id' => '01991a00-0000-7000-8000-000000000001',
            'destination_place_id' => '01991a00-0000-7000-8000-000000000002',
            'recurrence' => 'weekdays',
            'departure_time' => '08:25',
            'seats_offered' => 3,
            'rules' => [
                'no_smoking' => true,
                'music_ok' => false,
                'no_pets' => false,
                'quiet' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function route(): array
    {
        return [
            'id' => '01991b00-0000-7000-8000-0000000000a1',
            'origin' => ['id' => '01991a00-0000-7000-8000-000000000001', 'label' => 'Kadıköy, Vapur İskelesi'],
            'destination' => ['id' => '01991a00-0000-7000-8000-000000000002', 'label' => 'Levent, Metro İstasyonu'],
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
            'status' => 'published',
            'published_at' => '2026-08-24T09:41:00+00:00',
            'cancelled_at' => null,
        ];
    }

    // ------------------------------------------------------- the surface

    public function test_the_four_route_operations_are_documented(): void
    {
        /** @var array<string, array<string, mixed>> $paths */
        $paths = self::contractDocument()['paths'];

        foreach ([
            '/api/v1/places' => 'get',
            '/api/v1/routes' => 'post',
            '/api/v1/me/routes' => 'get',
            '/api/v1/routes/{routeId}/cancel' => 'post',
        ] as $path => $method) {
            self::assertArrayHasKey($path, $paths, $path);
            self::assertArrayHasKey($method, $paths[$path], "$method $path");
        }
    }

    /**
     * Publishing answers twice, and both answers are documented.
     *
     * 201 for a journey this request created, 200 for one a retry found already
     * there. A contract that only described the 201 would leave every client
     * author to discover the retry case in production.
     */
    public function test_publication_documents_both_success_answers(): void
    {
        /** @var array<string, mixed> $responses */
        $responses = self::contractDocument()['paths']['/api/v1/routes']['post']['responses'];

        // Integers, not strings: PHP casts numeric array keys, so the YAML's
        // '201' arrives here as 201.
        self::assertSame([201, 200, 401, 403, 409, 422], array_keys($responses));
    }

    public function test_cancellation_documents_its_refusals(): void
    {
        /** @var array<string, mixed> $responses */
        $responses = self::contractDocument()['paths']['/api/v1/routes/{routeId}/cancel']['post']['responses'];

        self::assertSame([200, 401, 403, 404, 409], array_keys($responses));
    }

    /**
     * No body at all, because the transition names its own target state.
     */
    public function test_cancellation_takes_no_request_body(): void
    {
        /** @var array<string, mixed> $operation */
        $operation = self::contractDocument()['paths']['/api/v1/routes/{routeId}/cancel']['post'];

        self::assertArrayNotHasKey('requestBody', $operation);
    }

    /**
     * Neither mechanism is asked for anywhere.
     *
     * Structure, never prose. The descriptions explain WHY there is no
     * idempotency header and no expected_status field, so a text search would
     * report the explanation as the defect — the same trap the vocabulary guard
     * avoids by reading identifiers rather than sentences.
     */
    public function test_no_operation_asks_for_an_idempotency_key(): void
    {
        /** @var array<string, array<string, mixed>> $paths */
        $paths = self::contractDocument()['paths'];

        foreach ($paths as $path => $operations) {
            foreach ($operations as $method => $operation) {
                if (! is_array($operation)) {
                    continue;
                }

                /** @var list<array<string, mixed>> $parameters */
                $parameters = $operation['parameters'] ?? [];
                foreach ($parameters as $parameter) {
                    self::assertNotSame(
                        'Idempotency-Key',
                        $parameter['name'] ?? null,
                        "$method $path asks for an idempotency header",
                    );
                }

                $body = $operation['requestBody']['content']['application/json']['schema'] ?? null;
                if (is_array($body)) {
                    self::assertArrayNotHasKey('expected_status', $body['properties'] ?? []);
                }
            }
        }

        /** @var array<string, array<string, mixed>> $schemas */
        $schemas = self::contractDocument()['components']['schemas'];
        foreach ($schemas as $name => $schema) {
            self::assertArrayNotHasKey(
                'expected_status',
                $schema['properties'] ?? [],
                "$name carries an expected_status field",
            );
        }
    }

    // ------------------------------------------------- the departure rule

    public function test_a_weekday_commute_without_a_date_is_valid(): void
    {
        $this->assertValidates($this->publication(), 'RoutePublication');
    }

    public function test_a_one_off_journey_with_a_date_is_valid(): void
    {
        $this->assertValidates(
            ['recurrence' => 'once', 'departure_date' => '2026-09-14'] + $this->publication(),
            'RoutePublication',
        );
    }

    /**
     * CARRIES WEIGHT. The document refuses exactly what the database refuses.
     */
    public function test_a_weekday_commute_carrying_a_date_is_refused(): void
    {
        $this->assertRejects(
            ['recurrence' => 'weekdays', 'departure_date' => '2026-09-14'] + $this->publication(),
            'RoutePublication',
            'a recurring journey must not state a single date',
        );
    }

    public function test_a_one_off_journey_without_a_date_is_refused(): void
    {
        $this->assertRejects(
            ['recurrence' => 'once'] + $this->publication(),
            'RoutePublication',
            'a one-off journey must state its date',
        );
    }

    public function test_an_impossible_recurrence_is_refused(): void
    {
        $this->assertRejects(
            ['recurrence' => 'monthly'] + $this->publication(),
            'RoutePublication',
            'only once and weekdays exist',
        );
    }

    // ---------------------------------------------------------- the formats

    /**
     * `format: uuid` alone would accept a v4, so the pattern pins the version.
     */
    public function test_a_version_4_route_id_is_refused(): void
    {
        $this->assertRejects(
            ['id' => '9f1b7f4e-6c2a-4a5e-8f3d-2b1c4d5e6f70'] + $this->publication(),
            'RoutePublication',
            'the route id must be a version 7 UUID',
        );
    }

    /**
     * The backend rejects seconds, so the contract must not suggest otherwise.
     */
    public function test_a_departure_time_carrying_seconds_is_refused(): void
    {
        foreach (['08:00:00', '8:00', '25:00', '08:60'] as $time) {
            $this->assertRejects(
                ['departure_time' => $time] + $this->publication(),
                'RoutePublication',
                "$time is not a departure time this API accepts",
            );
        }
    }

    public function test_offering_no_seats_is_refused(): void
    {
        $this->assertRejects(
            ['seats_offered' => 0] + $this->publication(),
            'RoutePublication',
            'a shared journey offers at least one seat',
        );
    }

    /**
     * The maximum is the column's capacity, not a rule about cars.
     */
    public function test_the_seat_maximum_is_the_storage_bound(): void
    {
        /** @var array<string, mixed> $seats */
        $seats = self::contractDocument()['components']['schemas']['SeatsOffered'];

        self::assertSame(1, $seats['minimum']);
        self::assertSame(32767, $seats['maximum']);
        self::assertStringContainsString('storage bound, not a policy', (string) $seats['description']);
    }

    // ------------------------------------------- what may not reach the wire

    /**
     * CARRIES WEIGHT. Coordinates stay on the server.
     */
    public function test_a_place_exposes_only_an_id_and_a_label(): void
    {
        /** @var array<string, mixed> $place */
        $place = self::contractDocument()['components']['schemas']['Place'];

        self::assertSame(['id', 'label'], array_keys($place['properties']));
        self::assertFalse($place['additionalProperties']);

        foreach (['latitude', 'longitude', 'point', 'slug', 'timezone', 'provenance'] as $leak) {
            $this->assertRejects(
                ['id' => '01991a00-0000-7000-8000-000000000001', 'label' => 'Kadıköy', $leak => 'anything'],
                'Place',
                "$leak must not be able to reach a client",
            );
        }
    }

    /**
     * RideMate charges nobody, so no schema may name money.
     */
    public function test_no_route_or_place_schema_names_a_cost(): void
    {
        /** @var array<string, array<string, mixed>> $schemas */
        $schemas = self::contractDocument()['components']['schemas'];

        $forbidden = [
            'cost', 'cost_share', 'cost_share_per_person', 'fare', 'price',
            'amount', 'currency', 'suggestion', 'earnings', 'payout', 'total',
        ];

        foreach ([
            'Route', 'RoutePublication', 'RouteEnvelope', 'RoutePage',
            'Place', 'PlaceCatalogue', 'RideRules',
        ] as $name) {
            $properties = array_keys($schemas[$name]['properties'] ?? []);

            foreach ($properties as $property) {
                foreach ($forbidden as $word) {
                    self::assertStringNotContainsString(
                        $word,
                        (string) $property,
                        "$name.$property names money, and no route carries any",
                    );
                }
            }
        }
    }

    /**
     * The route says nothing about the driver, because nothing true is known.
     */
    public function test_a_route_carries_no_person(): void
    {
        /** @var array<string, mixed> $route */
        $route = self::contractDocument()['components']['schemas']['Route'];

        foreach ([
            'driver', 'driver_name', 'rating', 'verified', 'is_verified',
            'trust_score', 'trip_count', 'compatibility', 'walk_minutes',
            'vehicle', 'plate', 'avatar', 'account', 'account_id',
        ] as $absent) {
            self::assertArrayNotHasKey($absent, $route['properties'], $absent);
        }
    }

    // ------------------------------------------------------ the response shape

    public function test_a_published_route_validates(): void
    {
        $this->assertValidates($this->route(), 'Route');
        $this->assertValidates(['route' => $this->route()], 'RouteEnvelope');
    }

    public function test_a_cancelled_route_validates(): void
    {
        $this->assertValidates(
            ['status' => 'cancelled', 'cancelled_at' => '2026-08-24T10:00:00+00:00'] + $this->route(),
            'Route',
        );
    }

    public function test_a_one_off_route_carries_its_date(): void
    {
        $this->assertValidates(
            ['recurrence' => 'once', 'departure_date' => '2026-09-14'] + $this->route(),
            'Route',
        );
    }

    public function test_departure_state_admits_only_upcoming_and_past(): void
    {
        $this->assertRejects(
            ['departure_state' => 'expired'] + $this->route(),
            'Route',
            'a departure is upcoming or past; there is no third state',
        );
    }

    public function test_a_page_ends_with_a_null_cursor(): void
    {
        $this->assertValidates(
            ['routes' => [$this->route()], 'next_cursor' => null],
            'RoutePage',
        );

        $this->assertValidates(
            ['routes' => [], 'next_cursor' => 'opaque-token'],
            'RoutePage',
        );
    }

    public function test_a_page_must_state_whether_it_ended(): void
    {
        $this->assertRejects(
            ['routes' => []],
            'RoutePage',
            'next_cursor is required, because its absence would be ambiguous',
        );
    }

    public function test_the_catalogue_validates(): void
    {
        $this->assertValidates(
            ['places' => [['id' => '01991a00-0000-7000-8000-000000000001', 'label' => 'Kadıköy, Vapur İskelesi']]],
            'PlaceCatalogue',
        );
    }

    /**
     * Every new object refuses what it did not declare.
     */
    public function test_every_new_schema_closes_its_object(): void
    {
        /** @var array<string, array<string, mixed>> $schemas */
        $schemas = self::contractDocument()['components']['schemas'];

        foreach ([
            'Place', 'PlaceCatalogue', 'RideRules', 'RoutePublication',
            'Route', 'RouteEnvelope', 'RoutePage',
        ] as $name) {
            self::assertFalse(
                $schemas[$name]['additionalProperties'] ?? null,
                "$name accepts undeclared properties",
            );
        }
    }

    /**
     * The cursor is opaque, and the contract must not describe its contents.
     */
    public function test_the_cursor_is_documented_as_opaque(): void
    {
        /** @var array<string, mixed> $cursor */
        $cursor = self::contractDocument()['components']['parameters']['Cursor'];

        $description = (string) $cursor['description'];

        self::assertStringContainsString('Opaque', $description);
        self::assertStringNotContainsString('created_at', $description);
        self::assertStringNotContainsString('base64', $description);
    }
}
