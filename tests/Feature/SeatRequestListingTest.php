<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Place;
use App\Models\Route;
use App\Profiles\DisplayName;
use App\Profiles\SaveProfile;
use App\Routes\CancelRoute;
use App\Routes\DepartureState;
use App\Routes\PublishRoute;
use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteCursor;
use App\Routes\RouteDeparture;
use App\Routes\RouteStatus;
use App\SeatRequests\AcceptSeatRequest;
use App\SeatRequests\DeclineSeatRequest;
use App\SeatRequests\IncomingSeatRequest;
use App\SeatRequests\ListMySeatRequests;
use App\SeatRequests\ListRouteSeatRequests;
use App\SeatRequests\OwnSeatRequest;
use App\SeatRequests\RequestSeat;
use App\SeatRequests\SeatRequestPage;
use App\SeatRequests\SeatRequestStatus;
use App\SeatRequests\WithdrawSeatRequest;
use App\Support\KeysetCursor;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use ReflectionClass;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * The two lists, and the one thing they must not do: hide history.
 *
 * A request stops being discoverable the moment its journey is cancelled or
 * departs. It does not stop being something this member asked for, and these
 * are the only surfaces that say what came of it.
 */
final class SeatRequestListingTest extends TestCase
{
    use CreatesAccounts;
    use RefreshDatabase;

    private const KADIKOY = 'kadikoy-iskele';

    private const LEVENT = 'levent-metro';

    private const MASLAK = 'maslak-oto-sanayi';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(PilotPlaceSeeder::class);
    }

    // -------------------------------------------------- passenger listing

    public function test_a_member_sees_their_own_request_with_its_journey(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $passenger = $this->passenger();
        $this->ask($passenger, $this->id('01'), $route);

        $page = $this->mine($passenger);

        self::assertCount(1, $page->requests);
        $own = $page->requests[0];
        self::assertInstanceOf(OwnSeatRequest::class, $own);
        self::assertSame($this->id('01'), $own->request->id);
        self::assertSame(SeatRequestStatus::Pending, $own->request->status);
        self::assertSame($route->id, $own->route->id);
        self::assertSame(RouteStatus::Published, $own->route->status);
        self::assertSame(DepartureState::Upcoming, $own->departureState);
        self::assertSame('İrem Yılmaz', $own->driver->display_name);
        self::assertSame('İY', $own->driver->initials());
        self::assertNull($page->nextCursor);
    }

    public function test_a_member_sees_only_their_own_requests(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $mine = $this->passenger('+905322220001');
        $theirs = $this->passenger('+905322220002');

        $this->ask($mine, $this->id('01'), $route);
        $this->ask($theirs, $this->id('02'), $route);

        $page = $this->mine($mine);

        self::assertCount(1, $page->requests);
        self::assertSame($this->id('01'), $page->requests[0]->request->id);
    }

    /** Every answer is an answer, including the ones a member would rather not have. */
    public function test_all_four_statuses_appear_in_a_members_history(): void
    {
        $driver = $this->driver();
        $passenger = $this->passenger();

        $pending = $this->route($driver, $this->routeId('01'));
        $accepted = $this->route($driver, $this->routeId('02'), self::MASLAK);
        $declined = $this->route($driver, $this->routeId('03'), self::MASLAK);
        $withdrawn = $this->route($driver, $this->routeId('04'), self::MASLAK);

        $this->ask($passenger, $this->id('01'), $pending);
        $this->ask($passenger, $this->id('02'), $accepted);
        $this->ask($passenger, $this->id('03'), $declined);
        $this->ask($passenger, $this->id('04'), $withdrawn);

        app(AcceptSeatRequest::class)($driver, $this->id('02'));
        app(DeclineSeatRequest::class)($driver, $this->id('03'));
        app(WithdrawSeatRequest::class)($passenger, $this->id('04'));

        $statuses = array_map(
            static fn (OwnSeatRequest $own): SeatRequestStatus => $own->request->status,
            $this->mine($passenger)->requests,
        );

        self::assertEqualsCanonicalizing(SeatRequestStatus::cases(), $statuses);
    }

    /**
     * The two truths, side by side.
     *
     * The request stays exactly what it was; the journey says what it now is.
     * Neither is rewritten to agree with the other.
     */
    public function test_a_cancelled_journey_stays_in_history_with_its_real_status(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $passenger = $this->passenger();
        $this->ask($passenger, $this->id('01'), $route);
        app(AcceptSeatRequest::class)($driver, $this->id('01'));
        app(CancelRoute::class)($driver, $route->id);

        $own = $this->mine($passenger)->requests[0];

        self::assertSame(SeatRequestStatus::Accepted, $own->request->status);
        self::assertSame(RouteStatus::Cancelled, $own->route->status);
    }

    public function test_a_departed_journey_stays_in_history_with_its_real_departure_state(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $passenger = $this->passenger();
        $this->ask($passenger, $this->id('01'), $route);

        $own = $this->mine(
            $passenger,
            now: CarbonImmutable::now()->addDays(30),
        )->requests[0];

        self::assertSame(SeatRequestStatus::Pending, $own->request->status);
        self::assertSame(RouteStatus::Published, $own->route->status);
        self::assertSame(DepartureState::Past, $own->departureState);
    }

    public function test_a_members_history_is_newest_first(): void
    {
        $driver = $this->driver();
        $passenger = $this->passenger();

        foreach (['01', '02', '03'] as $index => $tail) {
            $route = $this->route($driver, $this->routeId($tail), self::MASLAK);
            $this->ask($passenger, $this->id($tail), $route);
        }

        self::assertSame(
            [$this->id('03'), $this->id('02'), $this->id('01')],
            $this->idsOf($this->mine($passenger)),
        );
    }

    // ----------------------------------------------------- driver listing

    public function test_a_driver_sees_the_requests_on_their_journey(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $this->ask($this->passenger('+905322220001'), $this->id('01'), $route);

        $page = $this->incoming($driver, $route->id);

        self::assertCount(1, $page->requests);
        $incoming = $page->requests[0];
        self::assertInstanceOf(IncomingSeatRequest::class, $incoming);
        self::assertSame($this->id('01'), $incoming->request->id);
        self::assertSame('Ayşe Demir', $incoming->passenger->display_name);
        self::assertSame('AD', $incoming->passenger->initials());
    }

    public function test_another_driver_gets_a_non_disclosing_miss(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $this->ask($this->passenger(), $this->id('01'), $route);
        $stranger = $this->driver('+905321119999');

        $this->expectException(ModelNotFoundException::class);

        $this->incoming($stranger, $route->id);
    }

    public function test_an_unknown_route_is_a_non_disclosing_miss(): void
    {
        $this->expectException(ModelNotFoundException::class);

        $this->incoming($this->driver(), $this->routeId('ff'));
    }

    /** A driver who cancelled still has people to answer. */
    public function test_requests_remain_visible_after_the_journey_is_cancelled(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $this->ask($this->passenger(), $this->id('01'), $route);
        app(CancelRoute::class)($driver, $route->id);

        self::assertCount(1, $this->incoming($driver, $route->id)->requests);
    }

    public function test_requests_remain_visible_after_the_journey_departed(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $this->ask($this->passenger(), $this->id('01'), $route);

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(30));

        try {
            self::assertCount(1, $this->incoming($driver, $route->id)->requests);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_incoming_requests_are_newest_first(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);

        foreach (['01', '02', '03'] as $index => $tail) {
            $this->ask($this->passenger('+90532222000'.$index), $this->id($tail), $route);
        }

        self::assertSame(
            [$this->id('03'), $this->id('02'), $this->id('01')],
            $this->idsOf($this->incoming($driver, $route->id)),
        );
    }

    // --------------------------------------------------------- pagination

    public function test_a_members_history_pages_without_gaps_or_repeats(): void
    {
        $driver = $this->driver();
        $passenger = $this->passenger();
        $tails = ['01', '02', '03', '04', '05'];
        foreach ($tails as $tail) {
            $route = $this->route($driver, $this->routeId($tail), self::MASLAK);
            $this->ask($passenger, $this->id($tail), $route);
        }

        $seen = [];
        $cursor = null;
        do {
            $page = $this->mine($passenger, $cursor, limit: 2);
            $seen = array_merge($seen, $this->idsOf($page));
            $cursor = $page->nextCursor;
        } while ($cursor instanceof KeysetCursor);

        self::assertSame(
            array_map(fn (string $tail): string => $this->id($tail), array_reverse($tails)),
            $seen,
        );
        self::assertSame(count($seen), count(array_unique($seen)));
    }

    /**
     * A row inserted mid-traversal does not shift the pages already walked.
     *
     * Keyset resumes from a position rather than an offset, so a newer request
     * lands ahead of the cursor and is simply not seen by this walk. Offset
     * pagination would have shown page two's first row twice.
     */
    public function test_inserting_during_traversal_neither_repeats_nor_skips(): void
    {
        $driver = $this->driver();
        $passenger = $this->passenger();
        foreach (['01', '02', '03', '04'] as $tail) {
            $route = $this->route($driver, $this->routeId($tail), self::MASLAK);
            $this->ask($passenger, $this->id($tail), $route);
        }

        $first = $this->mine($passenger, limit: 2);

        $newer = $this->route($driver, $this->routeId('09'), self::MASLAK);
        $this->ask($passenger, $this->id('09'), $newer);

        $second = $this->mine($passenger, $first->nextCursor, limit: 2);

        $seen = array_merge($this->idsOf($first), $this->idsOf($second));

        self::assertSame(
            [$this->id('04'), $this->id('03'), $this->id('02'), $this->id('01')],
            $seen,
        );
        self::assertNotContains($this->id('09'), $seen);
    }

    public function test_incoming_requests_page_without_gaps_or_repeats(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $tails = ['01', '02', '03'];
        foreach ($tails as $index => $tail) {
            $this->ask($this->passenger('+90532222000'.$index), $this->id($tail), $route);
        }

        $seen = [];
        $cursor = null;
        do {
            $page = $this->incoming($driver, $route->id, $cursor, limit: 1);
            $seen = array_merge($seen, $this->idsOf($page));
            $cursor = $page->nextCursor;
        } while ($cursor instanceof KeysetCursor);

        self::assertSame(
            [$this->id('03'), $this->id('02'), $this->id('01')],
            $seen,
        );
    }

    /** Null means the end, and only the end. */
    public function test_the_last_page_carries_no_cursor(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        $passenger = $this->passenger();
        $this->ask($passenger, $this->id('01'), $route);

        self::assertNull($this->mine($passenger, limit: 1)->nextCursor);
    }

    // ------------------------------------------------------- cursor scope

    public function test_each_surface_refuses_the_others_cursors(): void
    {
        $instant = CarbonImmutable::now();
        $id = $this->id('01');

        $mine = (new KeysetCursor($instant, $id, ListMySeatRequests::CURSOR))->encode();
        $route = (new KeysetCursor($instant, $id, ListRouteSeatRequests::CURSOR))->encode();
        $myRoutes = (new RouteCursor($instant, $id, RouteCursor::MY_ROUTES))->encode();
        $discovery = (new RouteCursor($instant, $id, RouteCursor::DISCOVERY))->encode();

        // Each decodes only on the surface that issued it.
        self::assertNotNull(KeysetCursor::decode($mine, ListMySeatRequests::CURSOR));
        self::assertNotNull(KeysetCursor::decode($route, ListRouteSeatRequests::CURSOR));

        foreach ([$route, $myRoutes, $discovery] as $foreign) {
            self::assertNull(KeysetCursor::decode($foreign, ListMySeatRequests::CURSOR));
        }

        foreach ([$mine, $myRoutes, $discovery] as $foreign) {
            self::assertNull(KeysetCursor::decode($foreign, ListRouteSeatRequests::CURSOR));
        }

        // And the two route surfaces still refuse the seat-request ones.
        self::assertNull(RouteCursor::decode($mine, RouteCursor::MY_ROUTES));
        self::assertNull(RouteCursor::decode($route, RouteCursor::DISCOVERY));
    }

    public function test_a_cursor_carries_no_readable_position(): void
    {
        $encoded = (new KeysetCursor(
            CarbonImmutable::now(),
            $this->id('01'),
            ListMySeatRequests::CURSOR,
        ))->encode();

        self::assertStringNotContainsString($this->id('01'), $encoded);
        self::assertStringNotContainsString(ListMySeatRequests::CURSOR, $encoded);
    }

    // ------------------------------------------------------------ privacy

    /**
     * The read models hand on a Profile and nothing else about a member.
     *
     * A Profile is a display name and the initials derived from it. There is no
     * Account on either tuple, so no phone number, credential or session can
     * reach a projection by way of a relation somebody follows later.
     */
    public function test_the_read_models_expose_only_profile_identity(): void
    {
        foreach ([OwnSeatRequest::class, IncomingSeatRequest::class] as $tuple) {
            foreach ((new ReflectionClass($tuple))->getProperties() as $property) {
                $type = (string) $property->getType();

                self::assertStringNotContainsString(
                    'Account',
                    $type,
                    "$tuple exposes an Account, which carries a phone number",
                );
            }
        }
    }

    // -------------------------------------------------------------- N + 1

    /**
     * A page costs the same number of queries whatever its size.
     *
     * Without the eager loads, each row would fetch its route, both places and
     * a profile on its own — five requests would be twenty-odd queries, and the
     * cost would only show up on a member who uses the product.
     */
    public function test_a_members_history_does_not_query_per_row(): void
    {
        $driver = $this->driver();
        $passenger = $this->passenger();
        foreach (['01', '02', '03', '04', '05'] as $tail) {
            $route = $this->route($driver, $this->routeId($tail), self::MASLAK);
            $this->ask($passenger, $this->id($tail), $route);
        }

        $one = $this->queriesFor(fn () => $this->mine($passenger, limit: 1));
        $five = $this->queriesFor(fn () => $this->mine($passenger, limit: 5));

        self::assertSame($one, $five, 'the history query count grows with the page');
    }

    public function test_incoming_requests_do_not_query_per_row(): void
    {
        $driver = $this->driver();
        $route = $this->route($driver);
        foreach (['01', '02', '03', '04'] as $index => $tail) {
            $this->ask($this->passenger('+90532222000'.$index), $this->id($tail), $route);
        }

        $one = $this->queriesFor(fn () => $this->incoming($driver, $route->id, limit: 1));
        $four = $this->queriesFor(fn () => $this->incoming($driver, $route->id, limit: 4));

        self::assertSame($one, $four, 'the incoming query count grows with the page');
    }

    // ------------------------------------------------------------ fixtures

    /** @return SeatRequestPage<OwnSeatRequest> */
    private function mine(
        Account $passenger,
        ?KeysetCursor $cursor = null,
        int $limit = ListMySeatRequests::DEFAULT_LIMIT,
        ?CarbonImmutable $now = null,
    ): SeatRequestPage {
        return app(ListMySeatRequests::class)($passenger, $cursor, $limit, $now);
    }

    /** @return SeatRequestPage<IncomingSeatRequest> */
    private function incoming(
        Account $driver,
        string $routeId,
        ?KeysetCursor $cursor = null,
        int $limit = ListRouteSeatRequests::DEFAULT_LIMIT,
    ): SeatRequestPage {
        return app(ListRouteSeatRequests::class)($driver, $routeId, $cursor, $limit);
    }

    /**
     * @param  SeatRequestPage<OwnSeatRequest|IncomingSeatRequest>  $page
     * @return list<string>
     */
    private function idsOf(SeatRequestPage $page): array
    {
        return array_map(
            static fn (OwnSeatRequest|IncomingSeatRequest $entry): string => $entry->request->id,
            $page->requests,
        );
    }

    private function queriesFor(callable $read): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $read();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    }

    private function ask(Account $passenger, string $requestId, Route $route): void
    {
        app(RequestSeat::class)($passenger, $requestId, $route->id);
    }

    private function driver(string $phone = '+905321110000'): Account
    {
        $account = $this->createAccount($phone);
        (new SaveProfile)($account, DisplayName::fromInput('İrem Yılmaz'));

        return $account;
    }

    private function passenger(string $phone = '+905322220000'): Account
    {
        $account = $this->createAccount($phone);
        (new SaveProfile)($account, DisplayName::fromInput('Ayşe Demir'));

        return $account;
    }

    private function route(
        Account $driver,
        ?string $routeId = null,
        string $destination = self::LEVENT,
        int $seats = 3,
    ): Route {
        $timezone = config('ridemate.pilot.timezone');
        self::assertIsString($timezone);

        return app(PublishRoute::class)(
            $driver,
            $routeId ?? $this->routeId('01'),
            $this->place(self::KADIKOY),
            $this->place($destination),
            RouteDeparture::fromInput(
                Recurrence::Once,
                CarbonImmutable::now()->addDays(3)->format('Y-m-d'),
                '08:00',
                $timezone,
            ),
            $seats,
            new RideRules(noSmoking: true, musicOk: false, noPets: false, quiet: false),
        )->route;
    }

    private function place(string $slug): Place
    {
        return Place::query()->where('slug', $slug)->sole();
    }

    private function id(string $tail): string
    {
        return '01991d00-0000-7000-8000-0000000000'.$tail;
    }

    private function routeId(string $tail): string
    {
        return '01991c00-0000-7000-8000-0000000000'.$tail;
    }
}
