<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Journeys\ServiceDate;
use App\Models\Route;
use App\SeatRequests\SeatRequestStatus;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Who is travelling on one dated journey, and the number each of them gave.
 *
 * WHY THIS EXISTS
 *
 * The pilot coordinates accepted journeys through an operator, not through the
 * app: no member ever sees another member's number. The operator still has to
 * find the driver and the accepted passengers of one exact `(route_id,
 * service_date)`, and the alternative is a hand-written join against the
 * production database — one missed `service_date` or `status` filter away from
 * printing every date's passengers, or the pending and declined ones. This
 * narrows that to one read with a fixed output. It reduces the risk; it does
 * not remove the operator's responsibility for what they do with the numbers.
 *
 * WHAT THE NUMBER IS
 *
 * `phone_e164` is the number the member signed in with. It is NOT proof of
 * identity or of a handset: without a production SMS adapter, no passcode has
 * provably reached any phone. Hence "contact phone", and nothing stronger.
 *
 * LEAST DISCLOSURE
 *
 * Route id, date and route status; each person's display name and number.
 * Nothing else is selected, so nothing else can be printed: no email, no ids,
 * no request history, no credentials. Nothing is logged, and nothing is written.
 *
 * A cancelled route is shown, not hidden. Its accepted requests stay accepted
 * (there is no `cancelled_by_route` state), and those are exactly the people an
 * operator needs to reach.
 */
final class ShowJourneyParticipants extends Command
{
    protected $signature = 'ridemate:journey:participants
        {route : The route id}
        {date : The service date, YYYY-MM-DD}';

    protected $description = 'Show the driver and accepted passengers of one dated journey, with contact phones';

    private const NO_DISPLAY_NAME = '(no display name)';

    public function handle(): int
    {
        $routeId = $this->argument('route');
        $day = ServiceDate::parse($this->argument('date'));

        if (! $day instanceof CarbonImmutable) {
            $this->error('That is not a service date. Use YYYY-MM-DD.');

            return self::FAILURE;
        }

        // Checked before the query: PostgreSQL rejects a malformed uuid with an
        // error rather than finding nothing.
        $route = Str::isUuid($routeId)
            ? Route::query()
                ->select(['id', 'account_id', 'recurrence', 'departure_date', 'departure_time', 'timezone', 'status'])
                ->find($routeId)
            : null;

        if (! $route instanceof Route) {
            $this->error('No route exists with that id.');

            return self::FAILURE;
        }

        // The recurrence decides, in the one place that owns it.
        if (! $route->runsOn($day)) {
            $this->error('That route does not run on that date.');

            return self::FAILURE;
        }

        $this->line("Route:        {$route->id}");
        $this->line('Service date: '.$day->toDateString());
        $this->line("Route status: {$route->status->value}");

        $this->newLine();
        $this->table(['Driver', 'Contact phone'], $this->contacts(
            DB::table('accounts')->where('accounts.id', $route->account_id),
        ));

        $passengers = $this->contacts(
            DB::table('seat_requests')
                ->join('accounts', 'accounts.id', '=', 'seat_requests.account_id')
                ->where('seat_requests.route_id', $route->id)
                ->where('seat_requests.service_date', $day->toDateString())
                ->where('seat_requests.status', SeatRequestStatus::Accepted->value)
                ->orderBy('seat_requests.decided_at')
                ->orderBy('seat_requests.id'),
        );

        $this->newLine();

        if ($passengers === []) {
            $this->line('No accepted passengers.');

            return self::SUCCESS;
        }

        $this->table(['Accepted passenger', 'Contact phone'], $passengers);

        return self::SUCCESS;
    }

    /**
     * Display name and number, and only those two columns.
     *
     * Asking for a seat requires a profile; publishing a route does not, so a
     * driver may have no display name. The number is still what the operator
     * needs, so a missing name is a placeholder rather than a failure.
     *
     * @return list<array{string, string}>
     */
    private function contacts(Builder $accounts): array
    {
        $rows = $accounts
            ->leftJoin('profiles', 'profiles.account_id', '=', 'accounts.id')
            ->get(['profiles.display_name', 'accounts.phone_e164']);

        $contacts = [];
        foreach ($rows as $row) {
            $name = $row->display_name;
            $phone = $row->phone_e164;

            $contacts[] = [
                is_string($name) ? $name : self::NO_DISPLAY_NAME,
                is_string($phone) ? $phone : '',
            ];
        }

        return $contacts;
    }
}
