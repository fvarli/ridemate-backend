<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Place;
use App\Models\Route;
use App\Profiles\DisplayName;
use App\Profiles\SaveProfile;
use App\Routes\CancelRoute;
use App\Routes\PublishRoute;
use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteDeparture;
use App\SeatRequests\AcceptSeatRequest;
use App\SeatRequests\DeclineSeatRequest;
use App\SeatRequests\RequestSeat;
use App\SeatRequests\WithdrawSeatRequest;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * The operator's view of one dated journey, and everything it must leave out.
 *
 * The pilot coordinates accepted journeys through an operator. What this
 * command prints is phone numbers, so the tests that carry weight are the ones
 * about who is NOT in the output: other dates, other statuses, other fields.
 */
final class JourneyParticipantsCommandTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    private const COMMAND = 'ridemate:journey:participants';

    private const DRIVER_PHONE = '+905321110000';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PilotPlaceSeeder::class);
    }

    public function test_it_shows_the_driver_and_accepted_passengers_of_the_exact_journey(): void
    {
        $driver = $this->member(self::DRIVER_PHONE, 'İrem Yılmaz');
        $route = $this->route($driver, Recurrence::Weekdays);
        $day = $this->nextWeekday();

        $this->accepted($driver, $route, $day, $this->member('+905322220001', 'Ayşe Demir'), '01');
        $this->accepted($driver, $route, $day, $this->member('+905322220002', 'Mehmet Kaya'), '02');

        [$exit, $output] = $this->participants($route->id, $day->toDateString());

        self::assertSame(0, $exit);
        self::assertStringContainsString($route->id, $output);
        self::assertStringContainsString($day->toDateString(), $output);
        self::assertStringContainsString('published', $output);
        self::assertStringContainsString('İrem Yılmaz', $output);
        self::assertStringContainsString(self::DRIVER_PHONE, $output);
        self::assertStringContainsString('Ayşe Demir', $output);
        self::assertStringContainsString('+905322220001', $output);
        self::assertStringContainsString('Mehmet Kaya', $output);
        self::assertStringContainsString('+905322220002', $output);
        self::assertStringNotContainsString('No accepted passengers', $output);
    }

    /** CARRIES WEIGHT. An asking that was never accepted discloses nothing. */
    public function test_pending_declined_and_withdrawn_passengers_are_left_out(): void
    {
        $driver = $this->member(self::DRIVER_PHONE, 'İrem Yılmaz');
        $route = $this->route($driver, Recurrence::Weekdays);
        $day = $this->nextWeekday();

        $pending = $this->member('+905322220001', 'Pending Person');
        $declined = $this->member('+905322220002', 'Declined Person');
        $withdrawn = $this->member('+905322220003', 'Withdrawn Person');

        $this->ask($pending, $route, $day, '01');
        $this->ask($declined, $route, $day, '02');
        app(DeclineSeatRequest::class)($driver, $this->requestId('02'));
        $this->ask($withdrawn, $route, $day, '03');
        app(WithdrawSeatRequest::class)($withdrawn, $this->requestId('03'));

        [$exit, $output] = $this->participants($route->id, $day->toDateString());

        self::assertSame(0, $exit);
        self::assertStringContainsString('No accepted passengers', $output);

        foreach (['Pending', 'Declined', 'Withdrawn'] as $name) {
            self::assertStringNotContainsString($name, $output);
        }

        foreach (['+905322220001', '+905322220002', '+905322220003'] as $phone) {
            self::assertStringNotContainsString($phone, $output);
        }
    }

    /** CARRIES WEIGHT. Monday's passenger is not Tuesday's. */
    public function test_a_passenger_accepted_for_another_date_is_left_out(): void
    {
        $driver = $this->member(self::DRIVER_PHONE, 'İrem Yılmaz');
        $route = $this->route($driver, Recurrence::Weekdays);
        $day = $this->nextWeekday();
        $otherDay = $this->nextWeekday(after: 1);

        $this->accepted($driver, $route, $day, $this->member('+905322220001', 'Ayşe Demir'), '01');
        $this->accepted($driver, $route, $otherDay, $this->member('+905322220002', 'Other Day'), '02');

        [$exit, $output] = $this->participants($route->id, $day->toDateString());

        self::assertSame(0, $exit);
        self::assertStringContainsString('+905322220001', $output);
        self::assertStringNotContainsString('Other Day', $output);
        self::assertStringNotContainsString('+905322220002', $output);
    }

    public function test_an_unknown_route_is_refused(): void
    {
        [$exit, $output] = $this->participants('01991c00-0000-7000-8000-000000000099', $this->nextWeekday()->toDateString());

        self::assertSame(1, $exit);
        self::assertStringContainsString('No route exists with that id.', $output);
    }

    public function test_a_route_argument_that_is_not_an_id_is_refused(): void
    {
        [$exit, $output] = $this->participants('not-a-route', $this->nextWeekday()->toDateString());

        self::assertSame(1, $exit);
        self::assertStringContainsString('No route exists with that id.', $output);
    }

    public function test_a_malformed_service_date_is_refused(): void
    {
        $route = $this->route($this->member(self::DRIVER_PHONE, 'İrem Yılmaz'), Recurrence::Weekdays);

        foreach (['tomorrow', '2026-02-30', '2026-9-28'] as $date) {
            [$exit, $output] = $this->participants($route->id, $date);

            self::assertSame(1, $exit, $date);
            self::assertStringContainsString('That is not a service date.', $output, $date);
            self::assertStringNotContainsString(self::DRIVER_PHONE, $output, $date);
        }
    }

    public function test_a_date_the_route_does_not_run_on_is_refused(): void
    {
        $driver = $this->member(self::DRIVER_PHONE, 'İrem Yılmaz');
        $weekdays = $this->route($driver, Recurrence::Weekdays);
        $once = $this->route($driver, Recurrence::Once, '02');

        $refusals = [
            [$weekdays->id, $this->nextSaturday()->toDateString()],
            [$once->id, $this->onceDate()->addDay()->toDateString()],
        ];

        foreach ($refusals as [$routeId, $date]) {
            [$exit, $output] = $this->participants($routeId, $date);

            self::assertSame(1, $exit, $date);
            self::assertStringContainsString('That route does not run on that date.', $output, $date);
            self::assertStringNotContainsString(self::DRIVER_PHONE, $output, $date);
        }
    }

    public function test_a_one_off_route_answers_for_its_own_date(): void
    {
        $driver = $this->member(self::DRIVER_PHONE, 'İrem Yılmaz');
        $route = $this->route($driver, Recurrence::Once);
        $this->accepted($driver, $route, null, $this->member('+905322220001', 'Ayşe Demir'), '01');

        [$exit, $output] = $this->participants($route->id, $this->onceDate()->toDateString());

        self::assertSame(0, $exit);
        self::assertStringContainsString('+905322220001', $output);
    }

    public function test_a_journey_without_accepted_passengers_says_so(): void
    {
        $route = $this->route($this->member(self::DRIVER_PHONE, 'İrem Yılmaz'), Recurrence::Weekdays);
        $day = $this->nextWeekday();

        [$exit, $output] = $this->participants($route->id, $day->toDateString());

        self::assertSame(0, $exit);
        self::assertStringContainsString($route->id, $output);
        self::assertStringContainsString('published', $output);
        self::assertStringContainsString('İrem Yılmaz', $output);
        self::assertStringContainsString(self::DRIVER_PHONE, $output);
        self::assertStringContainsString('No accepted passengers.', $output);
    }

    /** Publishing does not require a profile, so a driver may have no name. */
    public function test_a_driver_without_a_profile_still_has_a_number(): void
    {
        $route = $this->route($this->createAccount(self::DRIVER_PHONE), Recurrence::Weekdays);

        [$exit, $output] = $this->participants($route->id, $this->nextWeekday()->toDateString());

        self::assertSame(0, $exit);
        self::assertStringContainsString('(no display name)', $output);
        self::assertStringContainsString(self::DRIVER_PHONE, $output);
    }

    /**
     * CARRIES WEIGHT. A cancelled route is shown, not hidden.
     *
     * Cancelling a route leaves its accepted requests accepted, and those are
     * exactly the people the operator has to reach.
     */
    public function test_a_cancelled_route_is_identified_and_keeps_its_accepted_passengers(): void
    {
        $driver = $this->member(self::DRIVER_PHONE, 'İrem Yılmaz');
        $route = $this->route($driver, Recurrence::Weekdays);
        $day = $this->nextWeekday();
        $this->accepted($driver, $route, $day, $this->member('+905322220001', 'Ayşe Demir'), '01');

        app(CancelRoute::class)($driver, $route->id);

        [$exit, $output] = $this->participants($route->id, $day->toDateString());

        self::assertSame(0, $exit);
        self::assertStringContainsString('Route status: cancelled', $output);
        self::assertStringContainsString(self::DRIVER_PHONE, $output);
        self::assertStringContainsString('Ayşe Demir', $output);
        self::assertStringContainsString('+905322220001', $output);
    }

    /** CARRIES WEIGHT. Least disclosure: what is available is not what is printed. */
    public function test_nothing_beyond_names_and_numbers_is_printed(): void
    {
        $driver = $this->member(self::DRIVER_PHONE, 'İrem Yılmaz');
        $route = $this->route($driver, Recurrence::Weekdays);
        $day = $this->nextWeekday();
        $passenger = $this->member('+905322220001', 'Ayşe Demir');
        $this->accepted($driver, $route, $day, $passenger, '01');

        DB::table('accounts')->where('id', $passenger->id)->update([
            'email' => 'ayse@example.com',
            'email_verified_at' => CarbonImmutable::now(),
        ]);

        [, $output] = $this->participants($route->id, $day->toDateString());

        $absent = [
            'ayse@example.com',
            $driver->id,
            $passenger->id,
            $this->requestId('01'),
            $this->place('kadikoy-iskele')->label,
            $this->place('levent-metro')->label,
            'weekdays',
            'not_started',
            '08:00',
        ];

        foreach ($absent as $value) {
            self::assertStringNotContainsString($value, $output);
        }
    }

    /** CARRIES WEIGHT. Reading a journey writes nothing. */
    public function test_the_command_only_reads(): void
    {
        $driver = $this->member(self::DRIVER_PHONE, 'İrem Yılmaz');
        $route = $this->route($driver, Recurrence::Weekdays);
        $day = $this->nextWeekday();
        $this->accepted($driver, $route, $day, $this->member('+905322220001', 'Ayşe Demir'), '01');

        DB::flushQueryLog();
        DB::enableQueryLog();

        try {
            [$exit] = $this->participants($route->id, $day->toDateString());
        } finally {
            DB::disableQueryLog();
        }

        self::assertSame(0, $exit);

        $queries = DB::getQueryLog();
        self::assertNotEmpty($queries);

        foreach ($queries as $query) {
            self::assertStringStartsWith('select', strtolower(ltrim($query['query'])));
        }
    }

    /**
     * @return array{int, string}
     */
    private function participants(string $route, string $date): array
    {
        $exit = Artisan::call(self::COMMAND, ['route' => $route, 'date' => $date]);

        return [$exit, Artisan::output()];
    }

    private function member(string $phone, string $name): Account
    {
        $account = $this->createAccount($phone);
        (new SaveProfile)($account, DisplayName::fromInput($name));

        return $account;
    }

    private function route(Account $driver, Recurrence $recurrence, string $tail = '01'): Route
    {
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        return app(PublishRoute::class)(
            $driver,
            '01991c00-0000-7000-8000-0000000000'.$tail,
            $this->place('kadikoy-iskele'),
            $this->place('levent-metro'),
            RouteDeparture::fromInput(
                $recurrence,
                $recurrence === Recurrence::Once ? $this->onceDate()->toDateString() : null,
                '08:00',
                $timezone,
            ),
            3,
            new RideRules(noSmoking: true, musicOk: false, noPets: false, quiet: false),
        )->route;
    }

    private function ask(Account $passenger, Route $route, ?CarbonImmutable $day, string $tail): void
    {
        app(RequestSeat::class)($passenger, $this->requestId($tail), $route->id, $day);
    }

    private function accepted(Account $driver, Route $route, ?CarbonImmutable $day, Account $passenger, string $tail): void
    {
        $this->ask($passenger, $route, $day, $tail);
        app(AcceptSeatRequest::class)($driver, $this->requestId($tail));
    }

    private function place(string $slug): Place
    {
        return Place::query()->where('slug', $slug)->sole();
    }

    private function requestId(string $tail): string
    {
        return '01991d00-0000-7000-8000-0000000000'.$tail;
    }

    private function onceDate(): CarbonImmutable
    {
        return $this->bareToday()->addDays(3);
    }

    /**
     * The suite runs on whatever day it runs on, so the fixtures walk the
     * calendar rather than hard-coding one.
     */
    private function nextWeekday(int $after = 0): CarbonImmutable
    {
        $day = $this->bareToday();
        $remaining = $after + 1;

        while ($remaining > 0) {
            $day = $day->addDay();

            if ($day->dayOfWeekIso <= 5) {
                $remaining--;
            }
        }

        return $day;
    }

    private function nextSaturday(): CarbonImmutable
    {
        $day = $this->bareToday()->addDay();

        while ($day->dayOfWeekIso !== 6) {
            $day = $day->addDay();
        }

        return $day;
    }

    private function bareToday(): CarbonImmutable
    {
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        $day = CarbonImmutable::createFromFormat(
            '!Y-m-d',
            CarbonImmutable::now()->setTimezone($timezone)->format('Y-m-d'),
        );
        self::assertInstanceOf(CarbonImmutable::class, $day);

        return $day;
    }
}
