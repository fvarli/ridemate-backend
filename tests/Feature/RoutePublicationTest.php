<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Place;
use App\Models\Route;
use App\Routes\PublishedRoute;
use App\Routes\PublishRoute;
use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteDeparture;
use App\Routes\RoutePublicationRefused;
use App\Routes\RouteStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * Publishing a journey, and every way the database refuses a dishonest one.
 */
final class RoutePublicationTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    private const A_ROUTE_ID = '01991a00-0000-7000-8000-00000000a001';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PilotPlaceSeeder::class);
    }

    private function timezone(): string
    {
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        return $timezone;
    }

    private function place(string $slug): Place
    {
        return Place::query()->where('slug', $slug)->sole();
    }

    private function departure(
        Recurrence $recurrence = Recurrence::Weekdays,
        ?string $date = null,
        string $time = '08:00',
    ): RouteDeparture {
        return RouteDeparture::fromInput($recurrence, $date, $time, $this->timezone());
    }

    private function rules(bool $noPets = false): RideRules
    {
        return new RideRules(noSmoking: true, musicOk: false, noPets: $noPets, quiet: false);
    }

    private function publish(
        Account $driver,
        string $id = self::A_ROUTE_ID,
        ?RouteDeparture $departure = null,
        int $seats = 3,
        ?string $originSlug = null,
        ?string $destinationSlug = null,
        ?RideRules $rules = null,
        ?CarbonImmutable $now = null,
    ): PublishedRoute {
        return app(PublishRoute::class)(
            $driver,
            $id,
            $this->place($originSlug ?? 'kadikoy-iskele'),
            $this->place($destinationSlug ?? 'levent-metro'),
            $departure ?? $this->departure(),
            $seats,
            $rules ?? $this->rules(),
            $now,
        );
    }

    // ------------------------------------------------------------ publishing

    public function test_a_driver_publishes_a_recurring_journey(): void
    {
        $driver = $this->createAccount();

        $published = $this->publish($driver);

        self::assertFalse($published->wasAlreadyPublished);
        $route = $published->route;

        self::assertSame(self::A_ROUTE_ID, $route->id);
        self::assertSame($driver->id, $route->account_id);
        self::assertSame(Recurrence::Weekdays, $route->recurrence);
        self::assertNull($route->departure_date);
        self::assertSame('08:00', substr($route->departure_time, 0, 5));
        self::assertSame('Europe/Istanbul', $route->timezone);
        self::assertSame(3, $route->seats_offered);
        self::assertSame(RouteStatus::Published, $route->status);
        self::assertNull($route->cancelled_at);
    }

    public function test_a_driver_publishes_a_one_off_journey_with_a_date(): void
    {
        $driver = $this->createAccount();

        $route = $this->publish(
            $driver,
            departure: $this->departure(Recurrence::Once, '2099-04-01', '07:45'),
        )->route;

        self::assertSame(Recurrence::Once, $route->recurrence);
        self::assertSame('2099-04-01', $route->departure_date?->format('Y-m-d'));
        self::assertSame('07:45', substr($route->departure_time, 0, 5));
    }

    /**
     * The rules the screen collects are kept, not quietly dropped.
     */
    public function test_the_stated_ride_rules_are_persisted(): void
    {
        $driver = $this->createAccount();

        $route = $this->publish($driver, rules: new RideRules(
            noSmoking: true, musicOk: true, noPets: true, quiet: false,
        ))->route;

        self::assertTrue($route->rule_no_smoking);
        self::assertTrue($route->rule_music_ok);
        self::assertTrue($route->rule_no_pets);
        self::assertFalse($route->rule_quiet);
    }

    // ------------------------------------------------------------ refusals

    public function test_a_journey_to_the_same_place_is_refused(): void
    {
        $driver = $this->createAccount();

        $this->expectException(RoutePublicationRefused::class);

        $this->publish($driver, originSlug: 'levent-metro', destinationSlug: 'levent-metro');
    }

    public function test_offering_no_seats_is_refused(): void
    {
        $driver = $this->createAccount();

        $this->expectException(RoutePublicationRefused::class);

        $this->publish($driver, seats: 0);
    }

    /**
     * CARRIES WEIGHT. Past in Istanbul is past, whatever UTC thinks.
     */
    public function test_a_departure_already_past_in_the_pilot_timezone_is_refused(): void
    {
        $driver = $this->createAccount();
        $now = CarbonImmutable::parse('2026-06-15T09:30:00Z'); // 12:30 in Istanbul

        $this->expectException(RoutePublicationRefused::class);

        $this->publish(
            $driver,
            departure: $this->departure(Recurrence::Once, '2026-06-15', '12:00'),
            now: $now,
        );
    }

    // ---------------------------------------------------------- idempotency

    public function test_republishing_the_same_journey_creates_no_second_route(): void
    {
        $driver = $this->createAccount();

        $first = $this->publish($driver);
        $second = $this->publish($driver);

        self::assertFalse($first->wasAlreadyPublished);
        self::assertTrue($second->wasAlreadyPublished);
        self::assertSame($first->route->id, $second->route->id);
        self::assertSame(1, Route::query()->count());
        self::assertEquals(
            $first->route->published_at,
            $second->route->fresh()?->published_at,
        );
    }

    public function test_the_same_id_describing_a_different_journey_is_refused(): void
    {
        $driver = $this->createAccount();
        $this->publish($driver);

        $this->expectException(RoutePublicationRefused::class);

        $this->publish($driver, seats: 4);
    }

    public function test_another_members_id_is_refused(): void
    {
        $driver = $this->createAccount();
        $stranger = $this->createAccount('+905329998877');
        $this->publish($driver);

        $this->expectException(RoutePublicationRefused::class);

        $this->publish($stranger);
    }

    // --------------------------------------------- what the database refuses

    public function test_the_database_refuses_a_recurring_route_carrying_a_date(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRaw(['recurrence' => 'weekdays', 'departure_date' => '2099-01-01']);
    }

    public function test_the_database_refuses_a_one_off_route_without_a_date(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRaw(['recurrence' => 'once', 'departure_date' => null]);
    }

    public function test_the_database_refuses_identical_endpoints(): void
    {
        $origin = $this->place('kadikoy-iskele');

        $this->expectException(QueryException::class);

        $this->insertRaw([
            'origin_place_id' => $origin->id,
            'destination_place_id' => $origin->id,
        ]);
    }

    public function test_the_database_refuses_fewer_than_one_seat(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRaw(['seats_offered' => 0]);
    }

    public function test_the_database_refuses_an_unknown_status(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRaw(['status' => 'expired']);
    }

    public function test_the_database_refuses_a_cancellation_without_a_timestamp(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRaw(['status' => 'cancelled', 'cancelled_at' => null]);
    }

    public function test_the_database_refuses_a_published_route_with_a_cancellation_time(): void
    {
        $this->expectException(QueryException::class);

        $this->insertRaw(['status' => 'published', 'cancelled_at' => now()]);
    }

    // ----------------------------------------------------- what must not exist

    /**
     * RideMate charges nobody, so no column may suggest otherwise.
     */
    public function test_the_routes_table_carries_no_cost_column(): void
    {
        $forbidden = [
            'cost', 'cost_share', 'cost_share_per_person', 'fare', 'price',
            'amount', 'earnings', 'payout', 'revenue', 'commission',
        ];

        foreach (Schema::getColumnListing('routes') as $column) {
            foreach ($forbidden as $word) {
                self::assertStringNotContainsString(
                    $word,
                    $column,
                    "routes.$column names money, and no route carries any",
                );
            }
        }
    }

    /**
     * No `expired` status means no sweeper is needed to keep one true.
     */
    public function test_there_is_no_expired_status_and_nothing_schedules_one(): void
    {
        $definition = DB::scalar(
            "select pg_get_constraintdef(oid) from pg_constraint
             where conname = 'routes_status_check'"
        );

        self::assertIsString($definition);
        self::assertStringNotContainsString('expired', $definition);
        self::assertStringContainsString('published', $definition);
        self::assertStringContainsString('cancelled', $definition);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertRaw(array $overrides): void
    {
        $driver = $this->createAccount('+905321110000');

        DB::table('routes')->insert(array_merge([
            'id' => '01991a00-0000-7000-8000-00000000b001',
            'account_id' => $driver->id,
            'origin_place_id' => $this->place('kadikoy-iskele')->id,
            'destination_place_id' => $this->place('levent-metro')->id,
            'recurrence' => 'weekdays',
            'departure_date' => null,
            'departure_time' => '08:00',
            'timezone' => 'Europe/Istanbul',
            'seats_offered' => 3,
            'rule_no_smoking' => true,
            'rule_music_ok' => false,
            'rule_no_pets' => false,
            'rule_quiet' => false,
            'status' => 'published',
            'published_at' => now(),
            'cancelled_at' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
