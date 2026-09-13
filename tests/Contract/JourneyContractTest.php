<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\AssertionFailedError;
use Tests\TestCase;

/**
 * The dated-journey contract, and what it must refuse.
 *
 * A journey is the driver's own view of one day of one plan. What the document
 * does NOT admit matters most: this is the surface where a passenger count, a
 * seat figure or a plan's bookkeeping would look plausible and would be a claim
 * the product cannot make.
 */
final class JourneyContractTest extends TestCase
{
    use ValidatesTheContract;

    private const FEED = '/api/v1/me/journeys';

    private const DATED = '/api/v1/routes/{routeId}/journeys/{serviceDate}';

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
    private function journey(): array
    {
        return [
            'route_id' => '01991b00-0000-7000-8000-0000000000a1',
            'service_date' => '2026-09-16',
            'origin' => ['id' => '01991a00-0000-7000-8000-000000000001', 'label' => 'Kadıköy'],
            'destination' => ['id' => '01991a00-0000-7000-8000-000000000002', 'label' => 'Levent'],
            'departure_time' => '08:25',
            'timezone' => 'Europe/Istanbul',
            'route_status' => 'published',
            'trip' => [
                'state' => 'not_started',
                'started_at' => null,
                'completed_at' => null,
                'aborted_at' => null,
            ],
        ];
    }

    // ------------------------------------------------------------- documented

    public function test_both_operations_are_documented_and_authenticated(): void
    {
        /** @var array<string, mixed> $paths */
        $paths = self::contractDocument()['paths'];

        foreach ([self::FEED, self::DATED] as $path) {
            self::assertArrayHasKey($path, $paths);
            self::assertSame(['get'], array_keys($paths[$path]['get'] === null ? [] : $paths[$path]));
            self::assertSame([['bearerAuth' => []]], $paths[$path]['get']['security']);
        }

        // The feed is a listing: a bad cursor is a 422 and there is nothing to
        // not-find. The dated read addresses one resource: every refusal it has
        // is a 404, and it publishes no 422 at all.
        self::assertSame(
            [200, 401, 403, 422],
            array_keys($paths[self::FEED]['get']['responses']),
        );
        self::assertSame(
            [200, 401, 403, 404],
            array_keys($paths[self::DATED]['get']['responses']),
        );
    }

    /**
     * CARRIES WEIGHT. The feed takes exactly the two paging parameters.
     *
     * A date range, a status filter or a route id would each turn the bounded
     * feed into a history query it is not, and documenting one would promise it.
     */
    public function test_the_feed_takes_only_a_cursor_and_a_limit(): void
    {
        /** @var list<array<string, mixed>> $parameters */
        $parameters = self::contractDocument()['paths'][self::FEED]['get']['parameters'];

        $names = [];
        foreach ($parameters as $parameter) {
            /** @var array{'$ref'?: string} $parameter */
            $ref = (string) ($parameter['$ref'] ?? '');
            $names[] = substr($ref, strrpos($ref, '/') + 1);
        }

        self::assertSame(['Cursor', 'Limit'], $names);
    }

    /** The dated read is addressed by the journey's whole identity and nothing else. */
    public function test_the_dated_read_is_addressed_by_route_and_date(): void
    {
        /** @var list<array<string, mixed>> $parameters */
        $parameters = self::contractDocument()['paths'][self::DATED]['get']['parameters'];

        $names = [];
        foreach ($parameters as $parameter) {
            /** @var array{'$ref'?: string} $parameter */
            $ref = (string) ($parameter['$ref'] ?? '');
            $names[] = substr($ref, strrpos($ref, '/') + 1);
        }

        self::assertSame(['RouteId', 'ServiceDate'], $names);
    }

    // ----------------------------------------------------------------- shapes

    public function test_a_journey_validates(): void
    {
        $this->assertValidates($this->journey(), 'Journey');
        $this->assertValidates(
            ['journeys' => [$this->journey()], 'next_cursor' => null],
            'JourneyPage',
        );
        $this->assertValidates(['journeys' => [], 'next_cursor' => null], 'JourneyPage');
    }

    public function test_every_lifecycle_state_validates(): void
    {
        foreach (['not_started', 'in_progress', 'completed', 'aborted'] as $state) {
            $this->assertValidates(
                ['trip' => [
                    'state' => $state,
                    'started_at' => $state === 'not_started' ? null : '2026-09-16T05:25:00Z',
                    'completed_at' => $state === 'completed' ? '2026-09-16T06:10:00Z' : null,
                    'aborted_at' => $state === 'aborted' ? '2026-09-16T06:10:00Z' : null,
                ]] + $this->journey(),
                'Journey',
            );
        }
    }

    /**
     * CARRIES WEIGHT. A cancelled plan can still hold a journey.
     *
     * The two facts are independent, and the schema must not quietly couple
     * them by admitting only one combination.
     */
    public function test_a_cancelled_plan_with_a_running_journey_validates(): void
    {
        $this->assertValidates(
            [
                'route_status' => 'cancelled',
                'trip' => [
                    'state' => 'in_progress',
                    'started_at' => '2026-09-15T05:25:00Z',
                    'completed_at' => null,
                    'aborted_at' => null,
                ],
            ] + $this->journey(),
            'Journey',
        );
    }

    /**
     * CARRIES WEIGHT. The lifecycle is never null on THIS surface.
     *
     * `MyRoute.trip` is nullable because a plan has no single journey. A
     * journey is a concrete day, so the question always has an answer, and a
     * null here would be a second way to say `not_started`.
     */
    public function test_a_journey_may_not_omit_or_null_its_trip(): void
    {
        $withoutTrip = $this->journey();
        unset($withoutTrip['trip']);

        $this->assertRejects(
            $withoutTrip,
            'Journey',
            'the contract admitted a journey with no trip lifecycle',
        );

        $this->assertRejects(
            ['trip' => null] + $this->journey(),
            'Journey',
            'the contract admitted a null trip, which only a PLAN may have',
        );
    }

    /**
     * CARRIES WEIGHT. Every field this projection must never carry, one at a
     * time so a failure names which crept in.
     */
    public function test_no_plan_field_and_no_third_party_is_admitted(): void
    {
        foreach ([
            // The plan's, not the journey's.
            'recurrence' => 'weekdays',
            'seats_offered' => 3,
            'rules' => ['no_smoking' => true],
            'departure_date' => '2026-09-16',
            'departure_state' => 'upcoming',
            'published_at' => '2026-09-08T09:41:00+00:00',
            'cancelled_at' => null,
            'status' => 'published',
            // Somebody else's, or nobody's.
            'driver' => ['display_name' => 'İrem'],
            'passengers' => [],
            'passenger_count' => 2,
            'accepted_seats' => 1,
            'seats_available' => 2,
            // Things the product does not have at all.
            'cost_share_per_person' => 18,
            'rating' => 4.9,
            'is_verified' => true,
            'latitude' => 41.0,
            // A second name for an identity that already has one.
            'id' => '01991e00-0000-7000-8000-000000000001',
            'trip_id' => '01991e00-0000-7000-8000-000000000001',
        ] as $key => $value) {
            $this->assertRejects(
                [$key => $value] + $this->journey(),
                'Journey',
                "the contract admitted `$key`, which no journey may carry",
            );
        }
    }

    public function test_the_page_admits_nothing_beside_its_two_fields(): void
    {
        $this->assertRejects(
            ['journeys' => [], 'next_cursor' => null, 'total' => 0],
            'JourneyPage',
            'the page admitted a count, which a keyset feed does not have',
        );
    }

    // ------------------------------------------------------------- vocabulary

    public function test_the_journey_schemas_name_nothing_that_does_not_exist(): void
    {
        /** @var array<string, mixed> $schemas */
        $schemas = self::contractDocument()['components']['schemas'];

        $encoded = json_encode([$schemas['Journey'], $schemas['JourneyPage']]);
        self::assertIsString($encoded);

        foreach ([
            '"rating', '"trust', '"verified', '"trip_count', '"approval',
            '"passenger', '"seats', '"fare', '"price', '"cost', '"payment',
            '"latitude', '"longitude', '"distance', '"total', '"count',
        ] as $absent) {
            self::assertStringNotContainsString(
                $absent,
                $encoded,
                "the journey schemas name `$absent`, which this surface must not carry",
            );
        }
    }

    /**
     * CARRIES WEIGHT. The feed does not describe itself as history or a schedule.
     *
     * Both would be promises it cannot keep: yesterday's finished journeys are
     * absent, and so is tomorrow.
     */
    public function test_the_feed_does_not_promise_history_or_a_schedule(): void
    {
        $description = self::contractDocument()['paths'][self::FEED]['get']['description'];
        self::assertIsString($description);

        // A bare substring guard cannot work here: the description names both
        // claims in order to deny them, and "does not contain `upcoming`" would
        // fail on the very sentence that makes the promise honest. So the
        // denial is what is asserted, and the claims are checked only in the
        // forms no disclaimer would use.
        self::assertStringContainsString(
            'This is not trip history, and not "upcoming journeys".',
            $description,
            'the feed no longer says what it is not, so a client may read it as a schedule',
        );

        foreach ([
            'every journey', 'all of the caller', 'the caller\'s full',
            'complete history', 'trip history of',
        ] as $claim) {
            self::assertStringNotContainsStringIgnoringCase(
                $claim,
                $description,
                "the feed describes itself as `$claim`, which it is not",
            );
        }

        // And it says what it actually is.
        self::assertStringContainsString('still under way', $description);
    }
}
