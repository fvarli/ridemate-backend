<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Routes;

use App\Auth\AuthContext;
use App\Http\Requests\ListMyRoutesRequest;
use App\Http\Responses\MyRoutePayload;
use App\Models\Route;
use App\Routes\RouteCursor;
use Illuminate\Http\JsonResponse;

/**
 * `GET /api/v1/me/routes`.
 *
 * KEYSET, NOT OFFSET
 *
 * Ordered by `(created_at, id)` descending, resuming with a row comparison
 * rather than a row count. A member's list grows while they read it, and an
 * offset into a moving list shows some rows twice and skips others — a
 * correctness problem that is invisible against fixed test data and obvious to
 * the one person whose route went missing.
 *
 * `created_at` is the SERVER's. Ordering by the id would be tempting, since a
 * UUIDv7 is time-ordered, but that id came from the client and nothing obliges
 * a client to mint them in order. The id is still in the key, as the tie-break
 * for two routes published in the same microsecond.
 *
 * One row more than asked for is fetched, which is how the endpoint knows
 * whether a next page exists without a second query. The extra row is dropped
 * before the response is built.
 *
 * Scoped to the caller by the query itself. There is no parameter that could
 * widen it, and a cursor carries no identity — it names a position in a list
 * that is already someone's.
 */
final class ListMyRoutesController
{
    public function __invoke(ListMyRoutesRequest $request): JsonResponse
    {
        $member = AuthContext::of($request)->account;
        $limit = $request->limit();
        $cursor = $request->cursor();

        $query = Route::query()
            // `trip` is eager-loaded with the rest: without it every row would
            // fetch its own lifecycle during serialization, and a page would
            // cost a query per journey.
            ->with(['originPlace', 'destinationPlace', 'trips'])
            ->where('account_id', $member->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($cursor instanceof RouteCursor) {
            // PostgreSQL compares the tuple, which is exactly the ordering
            // above — one predicate rather than the nested OR that expressing
            // the same thing by hand would need.
            $query->whereRaw(
                '(created_at, id) < (?, ?)',
                [$cursor->createdAt, $cursor->id],
            );
        }

        $routes = $query->take($limit + 1)->get();

        $hasMore = $routes->count() > $limit;
        $page = $routes->take($limit);

        $payload = [];
        foreach ($page as $route) {
            $payload[] = MyRoutePayload::from($route);
        }

        $last = $page->last();

        return new JsonResponse([
            'routes' => $payload,
            // Null means the end. An empty page does not, which is why this is
            // always present rather than omitted when there is nothing after.
            'next_cursor' => $hasMore && $last instanceof Route
                ? (new RouteCursor($last->created_at, $last->id))->encode()
                : null,
        ]);
    }
}
