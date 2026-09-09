<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RefreshController;
use App\Http\Controllers\Api\V1\Auth\RequestPasscodeController;
use App\Http\Controllers\Api\V1\Auth\VerifyPasscodeController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Profiles\SaveProfileController;
use App\Http\Controllers\Api\V1\Profiles\ShowProfileController;
use App\Http\Controllers\Api\V1\Routes\CancelRouteController;
use App\Http\Controllers\Api\V1\Routes\DiscoverRoutesController;
use App\Http\Controllers\Api\V1\Routes\ListMyRoutesController;
use App\Http\Controllers\Api\V1\Routes\ListPlacesController;
use App\Http\Controllers\Api\V1\Routes\PublishRouteController;
use App\Http\Controllers\Api\V1\SeatRequests\AcceptSeatRequestController;
use App\Http\Controllers\Api\V1\SeatRequests\DeclineSeatRequestController;
use App\Http\Controllers\Api\V1\SeatRequests\ListMySeatRequestsController;
use App\Http\Controllers\Api\V1\SeatRequests\ListRouteSeatRequestsController;
use App\Http\Controllers\Api\V1\SeatRequests\RequestSeatController;
use App\Http\Controllers\Api\V1\SeatRequests\WithdrawSeatRequestController;
use Illuminate\Support\Facades\Route;

/*
 * The versioned product API — /api/v1.
 *
 * Every route here is described by openapi/openapi.yaml, which was written and
 * reviewed before any of this existed. The contract leads; these are its
 * implementation.
 *
 * ON THE MIDDLEWARE
 *
 * Throttles are named rather than inline so their budgets live together in
 * config and cannot drift apart route by route. See AppServiceProvider.
 *
 * `auth.token` appears on exactly one route. Authentication is opt-IN rather
 * than global-with-exceptions, because the passcode endpoints cannot be
 * authenticated by definition — and a global guard with a list of exemptions
 * fails open the day someone adds a route and forgets to exempt it. This way
 * the mistake is a route that refuses everyone, which is noticed immediately.
 *
 * Refresh deliberately carries no auth middleware: its credential is in the
 * body. See RefreshController.
 */

Route::post('auth/otp', RequestPasscodeController::class)
    ->middleware('throttle:rm-otp-request')
    ->name('auth.otp.request');

Route::post('auth/otp/verify', VerifyPasscodeController::class)
    ->middleware('throttle:rm-otp-verify')
    ->name('auth.otp.verify');

Route::post('auth/refresh', RefreshController::class)
    ->middleware('throttle:rm-auth-refresh')
    ->name('auth.refresh');

Route::post('auth/logout', LogoutController::class)
    ->middleware('auth.token')
    ->name('auth.logout');

/*
 * The signed-in member's own account.
 *
 * Not throttled. The credential-issuing endpoints carry per-address budgets
 * because they are what an attacker would hammer without one; a read of your
 * own account requires a credential you already had to obtain through those.
 */
Route::get('me', MeController::class)
    ->middleware('auth.token')
    ->name('me');

/*
 * The signed-in member's public identity.
 *
 * Separate from /me on purpose: that returns a credential — a phone number and
 * whether it may sign in — and this returns what other members will eventually
 * see. Folding them together would put a phone number one careless projection
 * away from every surface that needs a name.
 *
 * PUT rather than POST because the body names the state the profile should be
 * in, not a change to apply, so repeating it is safe by construction. There is
 * no DELETE: nothing in the product removes a profile, and an endpoint for it
 * would be a capability nobody asked for.
 *
 * Not throttled, for the same reason /me is not: the per-address budgets guard
 * the endpoints that hand out credentials, and both of these already require
 * one.
 */
Route::get('me/profile', ShowProfileController::class)
    ->middleware('auth.token')
    ->name('me.profile.show');

Route::put('me/profile', SaveProfileController::class)
    ->middleware('auth.token')
    ->name('me.profile.save');

/*
 * Publishing a journey, and the places one may run between.
 *
 * Not throttled, for the same reason /me is not: the per-address budgets guard
 * the endpoints that hand out credentials, and both of these already require
 * one. Publication is also idempotent on an id the client chose, so a retry
 * storm produces one route rather than a queue of them.
 */
Route::get('places', ListPlacesController::class)
    ->middleware('auth.token')
    ->name('places.index');

Route::post('routes', PublishRouteController::class)
    ->middleware('auth.token')
    ->name('routes.publish');

/*
 * A member's own journeys.
 *
 * The id is constrained to a UUIDv7 here rather than validated in the
 * controller, so a malformed one never matches the route at all and becomes an
 * ordinary 404 — which is what the contract publishes for this operation.
 * Validating it instead would have to invent a 422 the document does not
 * describe, and would answer differently for "not a route id" and "not your
 * route", which is a difference worth not telling anyone.
 */
/*
 * Journeys other members have published between two places.
 *
 * A literal segment under /routes, which cannot be captured by the parameterised
 * route below: that one constrains its id to a UUIDv7, and `discover` is not
 * one. The constraint is therefore load-bearing rather than cosmetic, and a
 * regression test says so.
 *
 * Not throttled, for the same reason the other authenticated reads are not: the
 * per-address budgets guard the endpoints that hand out credentials, and this
 * already requires one.
 */
Route::get('routes/discover', DiscoverRoutesController::class)
    ->middleware('auth.token')
    ->name('routes.discover');

Route::get('me/routes', ListMyRoutesController::class)
    ->middleware('auth.token')
    ->name('me.routes.index');

Route::post('routes/{routeId}/cancel', CancelRouteController::class)
    ->middleware('auth.token')
    ->where('routeId', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}')
    ->name('routes.cancel');

/*
 * Seat requests.
 *
 * A passenger asks, and the driver who owns the journey answers.
 *
 * Both `{routeId}` paths carry the same UUIDv7 constraint as cancellation, and
 * for the same load-bearing reason: `routes/discover` is a literal segment that
 * a looser parameter would swallow. `{requestId}` is constrained identically, so
 * a malformed id is an ordinary 404 rather than a 422 the contract does not
 * describe — and so it answers the same as an id that is real but not the
 * caller's, which is a difference worth not telling anyone.
 *
 * Not throttled, for the same reason the other authenticated writes are not:
 * the per-address budgets guard the endpoints that hand out credentials, these
 * already require one, and asking is idempotent on an id the client chose.
 */
Route::post('routes/{routeId}/seat-requests', RequestSeatController::class)
    ->middleware('auth.token')
    ->where('routeId', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}')
    ->name('routes.seat-requests.store');

Route::get('routes/{routeId}/seat-requests', ListRouteSeatRequestsController::class)
    ->middleware('auth.token')
    ->where('routeId', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}')
    ->name('routes.seat-requests.index');

Route::get('me/seat-requests', ListMySeatRequestsController::class)
    ->middleware('auth.token')
    ->name('me.seat-requests.index');

Route::post('seat-requests/{requestId}/withdraw', WithdrawSeatRequestController::class)
    ->middleware('auth.token')
    ->where('requestId', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}')
    ->name('seat-requests.withdraw');

Route::post('seat-requests/{requestId}/accept', AcceptSeatRequestController::class)
    ->middleware('auth.token')
    ->where('requestId', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}')
    ->name('seat-requests.accept');

Route::post('seat-requests/{requestId}/decline', DeclineSeatRequestController::class)
    ->middleware('auth.token')
    ->where('requestId', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-7[0-9a-fA-F]{3}-[89abAB][0-9a-fA-F]{3}-[0-9a-fA-F]{12}')
    ->name('seat-requests.decline');
