<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Place;
use App\Models\Route;
use App\Profiles\DisplayName;
use App\Profiles\SaveProfile;
use App\Routes\CancelRoute;
use App\Routes\DiscoveredRoute;
use App\Routes\DiscoverRoutes;
use App\Routes\DiscoveryPage;
use App\Routes\PublishRoute;
use App\Routes\Recurrence;
use App\Routes\RideRules;
use App\Routes\RouteCursor;
use App\Routes\RouteDeparture;
use App\Routes\RouteStatus;
use Carbon\CarbonImmutable;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesAccounts;
use Tests\TestCase;

/**
 * Finding somebody else's published journey between two places.
 *
 * Most of this file is about what discovery must NOT return. A query that
 * returns too much looks like it works: the route you were looking for is in
 * there, and the extra rows are somebody else's problem until a member requests
 * a seat on a journey that was cancelled, has already left, or is their own.
 */
final class RouteDiscoveryTest extends TestCase
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

    // ------------------------------------------------------------- fixtures

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

    /** An account that has completed the Phase 11 profile minimum. */
    private function driverNamed(string $phone, string $name): Account
    {
        $account = $this->createAccount($phone);
        (new SaveProfile)($account, DisplayName::fromInput($name));

        return $account;
    }

    private function publish(
        Account $driver,
        string $id,
        string $originSlug = self::KADIKOY,
        string $destinationSlug = self::LEVENT,
        Recurrence $recurrence = Recurrence::Weekdays,
        ?string $date = null,
        string $time = '08:00',
        ?CarbonImmutable $now = null,
    ): Route {
        return app(PublishRoute::class)(
            $driver,
            $id,
            $this->place($originSlug),
            $this->place($destinationSlug),
            RouteDeparture::fromInput($recurrence, $date, $time, $this->timezone()),
            3,
            new RideRules(noSmoking: true, musicOk: false, noPets: false, quiet: false),
            $now,
        )->route;
    }

    private function id(string $tail): string
    {
        return '01991c00-0000-7000-8000-0000000000'.$tail;
    }

    private function discover(
        Account $searcher,
        string $origin = self::KADIKOY,
        string $destination = self::LEVENT,
        ?RouteCursor $cursor = null,
        int $limit = 20,
        ?CarbonImmutable $now = null,
    ): DiscoveryPage {
        return app(DiscoverRoutes::class)(
            $searcher,
            $this->place($origin)->id,
            $this->place($destination)->id,
            $cursor,
            $limit,
            $now,
        );
    }

    /** @return list<string> */
    private function idsOf(DiscoveryPage $page): array
    {
        return array_map(
            static fn (DiscoveredRoute $found): string => $found->route->id,
            $page->routes,
        );
    }

    // --------------------------------------------------------------- matching

    public function test_a_journey_between_the_requested_places_is_found(): void
    {
        $driver = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');
        $this->publish($driver, $this->id('01'));

        self::assertSame([$this->id('01')], $this->idsOf($this->discover($searcher)));
    }

    /**
     * CARRIES WEIGHT. Direction is part of the match.
     *
     * Offering somebody a journey going the other way is worse than offering
     * nothing: it looks like a result.
     */
    public function test_the_reverse_direction_does_not_match(): void
    {
        $driver = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');
        // Published Levent -> Kadıköy, searched Kadıköy -> Levent.
        $this->publish($driver, $this->id('02'), self::LEVENT, self::KADIKOY);

        self::assertSame([], $this->idsOf($this->discover($searcher)));
    }

    /** A different destination is a different journey, not a near miss. */
    public function test_a_different_endpoint_does_not_match(): void
    {
        $driver = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');
        $this->publish($driver, $this->id('03'), self::KADIKOY, self::MASLAK);

        self::assertSame([], $this->idsOf($this->discover($searcher)));
    }

    // ------------------------------------------------------------- exclusions

    public function test_a_members_own_route_is_not_discovered(): void
    {
        $searcher = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $this->publish($searcher, $this->id('04'));

        self::assertSame([], $this->idsOf($this->discover($searcher)));
    }

    public function test_a_cancelled_route_is_not_discovered(): void
    {
        $driver = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');
        $route = $this->publish($driver, $this->id('05'));

        app(CancelRoute::class)($driver, $route->id);

        self::assertSame([], $this->idsOf($this->discover($searcher)));
        self::assertSame(
            RouteStatus::Cancelled,
            $route->fresh()?->status,
            'the fixture must actually be cancelled',
        );
    }

    /**
     * CARRIES WEIGHT. The departure rule is the domain's, read at query time.
     */
    public function test_a_one_off_whose_departure_has_passed_is_not_discovered(): void
    {
        $driver = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');

        $publishedAt = CarbonImmutable::parse('2026-09-10 07:00', $this->timezone());
        $this->publish(
            $driver,
            $this->id('06'),
            recurrence: Recurrence::Once,
            date: '2026-09-10',
            time: '08:00',
            now: $publishedAt,
        );

        // Still ahead at 07:30 …
        self::assertSame(
            [$this->id('06')],
            $this->idsOf($this->discover(
                $searcher,
                now: CarbonImmutable::parse('2026-09-10 07:30', $this->timezone()),
            )),
        );

        // … and behind us at 08:30, on the same day the coarse SQL bound keeps.
        self::assertSame(
            [],
            $this->idsOf($this->discover(
                $searcher,
                now: CarbonImmutable::parse('2026-09-10 08:30', $this->timezone()),
            )),
        );
    }

    /** A recurring commute has no single departure to be behind us. */
    public function test_a_published_weekday_route_stays_discoverable(): void
    {
        $driver = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');
        $this->publish($driver, $this->id('07'));

        self::assertSame(
            [$this->id('07')],
            $this->idsOf($this->discover(
                $searcher,
                now: CarbonImmutable::now()->addYears(2),
            )),
        );
    }

    /**
     * CARRIES WEIGHT. A route nobody can be named for is not discoverable.
     *
     * The whole point of a discovery result is that it names the member who
     * published it. A route whose owner never completed the profile minimum has
     * nothing honest to put there.
     */
    public function test_a_route_whose_owner_has_no_profile_is_not_discovered(): void
    {
        // Deliberately NOT driverNamed: this account never chose a name.
        $nameless = $this->createAccount('+905321234567');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');
        $this->publish($nameless, $this->id('08'));

        self::assertSame([], $this->idsOf($this->discover($searcher)));

        // And it appears the moment they complete setup — proving the exclusion
        // is the missing profile and not something else about the route.
        (new SaveProfile)($nameless, DisplayName::fromInput('Ayşe Demir'));

        self::assertSame([$this->id('08')], $this->idsOf($this->discover($searcher)));
    }

    /**
     * CARRIES WEIGHT. The profile-less route must be excluded BY THE QUERY, not
     * merely dropped afterwards.
     *
     * Dropping it in PHP produces the same visible answer on a full page and a
     * different one at a page boundary: the ineligible row would occupy a slot,
     * and a page could come back empty while eligible routes sat just behind
     * it. Publishing the nameless route LAST — so it sorts first — and asking
     * for one row is what separates the two implementations.
     */
    public function test_a_profile_less_route_does_not_consume_a_page_slot(): void
    {
        $named = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $nameless = $this->createAccount('+905323334444');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');

        $base = CarbonImmutable::parse('2026-09-10 09:00', $this->timezone());
        CarbonImmutable::setTestNow($base);
        $this->publish($named, $this->id('21'));
        CarbonImmutable::setTestNow($base->addSeconds(1));
        $this->publish($nameless, $this->id('22'));
        CarbonImmutable::setTestNow();

        // Newest first would be 22, which nobody can be named for.
        self::assertSame(
            [$this->id('21')],
            $this->idsOf($this->discover($searcher, limit: 1)),
            'the ineligible row must never reach the page window',
        );
    }

    // ------------------------------------------------------------ the driver

    /**
     * CARRIES WEIGHT. Identity comes from the Phase 11 domain, not from here.
     *
     * `irem yılmaz` is the input that separates the two possible
     * implementations: Turkish casing gives `İY`, and plain Unicode uppercasing
     * would give `IY`.
     */
    public function test_the_driver_is_named_by_the_authoritative_profile(): void
    {
        $driver = $this->driverNamed('+905321234567', 'irem yılmaz');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');
        $this->publish($driver, $this->id('09'));

        $found = $this->discover($searcher)->routes[0];

        self::assertSame('irem yılmaz', $found->driver->display_name);
        self::assertSame('İY', $found->driver->initials());
        self::assertSame(
            DisplayName::fromInput('irem yılmaz')->initials(),
            $found->driver->initials(),
            'initials must come from one implementation, not two',
        );
    }

    // ------------------------------------------------------------ pagination

    public function test_pages_are_stable_when_a_route_is_published_between_them(): void
    {
        $driver = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');

        $base = CarbonImmutable::parse('2026-09-10 09:00', $this->timezone());
        foreach (['11', '12', '13', '14'] as $offset => $tail) {
            CarbonImmutable::setTestNow($base->addSeconds($offset));
            $this->publish($driver, $this->id($tail));
        }
        CarbonImmutable::setTestNow();

        // Newest first: 14, 13, 12, 11.
        $first = $this->discover($searcher, limit: 2);
        self::assertSame([$this->id('14'), $this->id('13')], $this->idsOf($first));
        self::assertInstanceOf(RouteCursor::class, $first->nextCursor);

        // Somebody publishes while the searcher is reading. It sorts to the
        // front, which the second page must not see and must not be pushed by.
        CarbonImmutable::setTestNow($base->addMinutes(5));
        $this->publish($driver, $this->id('15'));
        CarbonImmutable::setTestNow();

        $second = $this->discover($searcher, cursor: $first->nextCursor, limit: 2);

        self::assertSame(
            [$this->id('12'), $this->id('11')],
            $this->idsOf($second),
            'no row may be skipped or repeated by an insert between pages',
        );
        self::assertNull($second->nextCursor);
    }

    public function test_the_cursor_is_discovery_specific(): void
    {
        $driver = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');
        $this->publish($driver, $this->id('16'));
        $this->publish($driver, $this->id('17'));

        $cursor = $this->discover($searcher, limit: 1)->nextCursor;
        self::assertInstanceOf(RouteCursor::class, $cursor);

        // It reads back as discovery …
        self::assertInstanceOf(
            RouteCursor::class,
            RouteCursor::decode($cursor->encode(), RouteCursor::DISCOVERY),
        );
        // … and is refused by the other list, which orders the same tuple over
        // a completely different set of rows.
        self::assertNull(
            RouteCursor::decode($cursor->encode(), RouteCursor::MY_ROUTES),
        );
    }

    /**
     * The owner join must not multiply a row.
     *
     * An account has one profile today, so a join could not duplicate — which
     * is exactly why this is worth pinning before something else is joined.
     */
    public function test_a_route_appears_once(): void
    {
        $driver = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');
        $this->publish($driver, $this->id('18'));

        $ids = $this->idsOf($this->discover($searcher));

        self::assertSame([$this->id('18')], $ids);
        self::assertSame(count($ids), count(array_unique($ids)));
    }

    // ------------------------------------------------- filling a full page

    /**
     * Publishes `$count` one-off routes that will already have departed, newest
     * first, so they sit in front of anything published before them.
     */
    private function publishDeparted(
        Account $driver,
        CarbonImmutable $from,
        int $count,
        int $offset = 40,
    ): void {
        for ($i = 0; $i < $count; $i++) {
            $at = $from->addSeconds($i);
            CarbonImmutable::setTestNow($at);
            $this->publish(
                $driver,
                $this->id((string) ($offset + $i)),
                recurrence: Recurrence::Once,
                date: '2026-09-10',
                time: '10:00',
                now: $at,
            );
        }
        CarbonImmutable::setTestNow();
    }

    /**
     * CARRIES WEIGHT. Rejected candidates must not shorten a public page.
     *
     * The database picks candidates and the domain decides which are eligible,
     * so a plain LIMIT would hand back a page short by however many the domain
     * rejected. Here five departed one-offs sit in front of three good routes
     * and a page of three is still a page of three.
     */
    public function test_ineligible_candidates_do_not_shorten_a_page(): void
    {
        $driver = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');

        $base = CarbonImmutable::parse('2026-09-10 09:00', $this->timezone());

        // Three eligible, published first so they sort last.
        foreach (['31', '32', '33'] as $offset => $tail) {
            CarbonImmutable::setTestNow($base->addSeconds($offset));
            $this->publish($driver, $this->id($tail));
        }
        CarbonImmutable::setTestNow();

        // Five that will have departed, in front of them.
        $this->publishDeparted($driver, $base->addMinutes(1), 5);

        $page = $this->discover(
            $searcher,
            limit: 3,
            now: CarbonImmutable::parse('2026-09-10 11:00', $this->timezone()),
        );

        self::assertSame(
            [$this->id('33'), $this->id('32'), $this->id('31')],
            $this->idsOf($page),
            'the page must be filled from behind the rejected candidates',
        );
        self::assertNull($page->nextCursor, 'there was nothing after them');
    }

    /**
     * CARRIES WEIGHT. Every eligible route exactly once, in order, across pages
     * that have to scan past rejected candidates to fill themselves.
     */
    public function test_paging_across_rejected_candidates_returns_each_route_once(): void
    {
        $driver = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');

        $base = CarbonImmutable::parse('2026-09-10 09:00', $this->timezone());

        // Four eligible, interleaved with departed one-offs so no window is
        // ever cleanly eligible or cleanly rejected.
        $expected = [];
        foreach (['51', '52', '53', '54'] as $i => $tail) {
            CarbonImmutable::setTestNow($base->addSeconds($i * 10));
            $this->publish($driver, $this->id($tail));
            array_unshift($expected, $this->id($tail));

            $this->publishDeparted($driver, $base->addSeconds($i * 10 + 1), 2, 60 + $i * 2);
        }
        CarbonImmutable::setTestNow();

        $now = CarbonImmutable::parse('2026-09-10 11:00', $this->timezone());

        $seen = [];
        $cursor = null;
        $pages = 0;

        do {
            $page = $this->discover($searcher, cursor: $cursor, limit: 2, now: $now);
            $seen = array_merge($seen, $this->idsOf($page));
            $cursor = $page->nextCursor;
            $pages++;

            self::assertLessThan(10, $pages, 'paging must terminate');
        } while ($cursor instanceof RouteCursor);

        self::assertSame($expected, $seen, 'every eligible route, once, newest first');
        self::assertSame(count($seen), count(array_unique($seen)));
    }

    /**
     * CARRIES WEIGHT. A null cursor means exhausted, not "this window ended".
     */
    public function test_a_page_of_only_rejected_candidates_is_empty_and_final(): void
    {
        $driver = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');

        $base = CarbonImmutable::parse('2026-09-10 09:00', $this->timezone());
        $this->publishDeparted($driver, $base, 4, 70);

        $page = $this->discover(
            $searcher,
            limit: 2,
            now: CarbonImmutable::parse('2026-09-10 11:00', $this->timezone()),
        );

        self::assertSame([], $this->idsOf($page));
        self::assertNull(
            $page->nextCursor,
            'no eligible route exists anywhere behind these, so this is the end',
        );
    }

    /**
     * And a full page still reports more when more genuinely follow, even with
     * rejected candidates in between.
     */
    public function test_a_filled_page_still_reports_what_follows(): void
    {
        $driver = $this->driverNamed('+905321234567', 'Ayşe Demir');
        $searcher = $this->driverNamed('+905329876543', 'Ali Can');

        $base = CarbonImmutable::parse('2026-09-10 09:00', $this->timezone());
        foreach (['81', '82', '83'] as $offset => $tail) {
            CarbonImmutable::setTestNow($base->addSeconds($offset));
            $this->publish($driver, $this->id($tail));
        }
        CarbonImmutable::setTestNow();
        $this->publishDeparted($driver, $base->addMinutes(1), 3, 90);

        $now = CarbonImmutable::parse('2026-09-10 11:00', $this->timezone());
        $first = $this->discover($searcher, limit: 2, now: $now);

        self::assertSame([$this->id('83'), $this->id('82')], $this->idsOf($first));
        self::assertInstanceOf(RouteCursor::class, $first->nextCursor);

        $second = $this->discover($searcher, cursor: $first->nextCursor, limit: 2, now: $now);

        self::assertSame([$this->id('81')], $this->idsOf($second));
        self::assertNull($second->nextCursor);
    }
}
