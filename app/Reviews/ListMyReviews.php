<?php

declare(strict_types=1);

namespace App\Reviews;

use App\Models\Account;
use App\Models\Review;
use App\Support\KeysetCursor;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * What other members have said about this one, newest first.
 *
 * ABOUT ME, NEVER BY ME
 *
 * A member's own reviews are not here. They wrote them and can read them from
 * `my_review` on the relationship itself; repeating them in the feed of things
 * said about you would be a list of two different kinds of fact.
 *
 * THE SUBJECT IS DERIVED, BECAUSE THE ROW DOES NOT NAME ANYBODY
 *
 * `reviews` holds no account column, deliberately. Which member a review is
 * about follows from the role and the relationship, each by exactly one path:
 *
 *     reviewer_role = 'driver'    → the driver wrote it; the passenger is the
 *                                   subject, and is seat_requests.account_id
 *     reviewer_role = 'passenger' → the passenger wrote it; the driver is the
 *                                   subject, and is routes.account_id
 *
 * A member therefore cannot receive a review about somebody else without the
 * join itself being wrong, rather than without a column happening to be right.
 *
 * NO COUNT, NO AVERAGE
 *
 * Not even for the member it is about. Phase 15 publishes no reputation, and a
 * number here is the first half of one.
 */
final class ListMyReviews
{
    /** This surface's cursors, refused by every other feed. */
    public const CURSOR = 'rm.reviews.v1';

    public const DEFAULT_LIMIT = 20;

    public const MAX_LIMIT = 50;

    public function __invoke(
        Account $subject,
        ?KeysetCursor $cursor = null,
        int $limit = self::DEFAULT_LIMIT,
        ?CarbonImmutable $now = null,
    ): ReviewPage {
        $query = Review::query()
            ->select('reviews.*')
            // The relationship, the journey and its making. All three are
            // needed to say who the subject is and whether the review has
            // released, and `trips.route_id` is unique so none of them
            // multiplies a row.
            ->join('seat_requests', 'seat_requests.id', '=', 'reviews.seat_request_id')
            ->join('routes', 'routes.id', '=', 'seat_requests.route_id')
            ->join('trips', 'trips.route_id', '=', 'routes.id')
            ->with([
                // Without these every row would fetch its reviewer's profile and
                // its journey's places one at a time.
                'seatRequest.route.originPlace',
                'seatRequest.route.destinationPlace',
                'seatRequest.route.account.profile',
                'seatRequest.passenger.profile',
            ])
            ->where(static function (Builder $aboutMe) use ($subject): void {
                $aboutMe
                    ->where(static function (Builder $wroteAsDriver) use ($subject): void {
                        $wroteAsDriver
                            ->where('reviews.reviewer_role', ReviewerRole::Driver->value)
                            ->where('seat_requests.account_id', $subject->id);
                    })
                    ->orWhere(static function (Builder $wroteAsPassenger) use ($subject): void {
                        $wroteAsPassenger
                            ->where('reviews.reviewer_role', ReviewerRole::Passenger->value)
                            ->where('routes.account_id', $subject->id);
                    });
            })
            ->orderByDesc('reviews.created_at')
            ->orderByDesc('reviews.id');

        ReleasedReviews::only($query, $now ?? CarbonImmutable::now());

        if ($cursor instanceof KeysetCursor) {
            // PostgreSQL compares the tuple, which is exactly the ordering
            // above — one predicate rather than a nested OR.
            $query->whereRaw('(reviews.created_at, reviews.id) < (?, ?)', [
                $cursor->createdAt,
                $cursor->id,
            ]);
        }

        $rows = $query->take($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        $page = $rows->take($limit);

        $reviews = [];
        foreach ($page as $review) {
            $reviews[] = $this->view($review);
        }

        $last = $page->last();

        return new ReviewPage(
            $reviews,
            // Names the last RETURNED review. A hidden row cannot be named here
            // because it was never a candidate — see `ReleasedReviews`.
            $hasMore && $last instanceof Review
                ? new KeysetCursor($last->created_at, $last->id, self::CURSOR)
                : null,
        );
    }

    /**
     * The reviewer's identity and the journey, from server-owned truth only.
     *
     * A relationship that reaches this point has both — a review cannot exist
     * without an accepted request on a published route, and neither party can
     * ask for or accept a seat without a profile. If one is somehow absent the
     * read fails rather than substituting a placeholder: a review attributed to
     * nobody in particular is worse than a page that says it could not load.
     */
    private function view(Review $review): ReceivedReview
    {
        $request = $review->seatRequest;
        $route = $request->route;

        $reviewer = $review->reviewer_role === ReviewerRole::Driver
            ? $route->account->profile
            : $request->passenger->profile;

        if ($reviewer === null) {
            throw new \RuntimeException(
                'A review exists for a member with no profile, which asking for '
                .'or accepting a seat should have made impossible.',
            );
        }

        return new ReceivedReview($review, $reviewer, $route);
    }
}
