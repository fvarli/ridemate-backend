# 8. Discovery v1 — exact endpoints, published plans

**Status:** accepted (Phase 12), implemented — `GET /api/v1/routes/discover` serves published
route plans and the client's Search and Match Results read it.

## Context

ADR 0006 deferred discovery because the match card named six things the product could not know.
Phase 11 supplied the one that gated everything else: a member has a `display_name` and
server-derived `initials`, so another member can finally be named honestly rather than invented.

That left the question ADR 0006 recorded as a revisit point — what discovery actually matches. The
roadmap in `docs/architecture.md` had promised `route_occurrences`, the first PostGIS corridor
query and its GiST index. All three were re-examined before any of them was built, and none of
them survived the examination. What follows is what was built instead, and why the promised work
was not.

## Decision

### Discovery matches exact endpoints, in the same direction

A search is `origin_place_id` + `destination_place_id`, both drawn from the same catalogue the
driver published against, and a route matches only when both ids are equal and in the same
direction. A journey from A to B is not a journey from B to A, and offering one as the other
would put a member in a car going the wrong way.

The catalogue is a small, curated set of named İstanbul places — ferry terminals, metro stations,
recognised districts — not an address space. Between named places of that kind, an exact endpoint
match *is* a real shared journey: two members who both chose `Kadıköy, Vapur İskelesi` are talking
about the same place, not two points that happen to be near each other. That is the whole
justification, and it holds only because of what the catalogue is. It should be re-opened when the
catalogue becomes fine-grained enough that two adjacent entries describe one place.

### There is no proximity of any kind

No radius, no corridor, no straight-line distance, no map-routing approximation, no walking time,
no "near your route". None of it exists in Phase 12, in the query or in the payload.

The reason is not that it is hard. A radius is easy and would immediately be wrong: it would tell
a member that a route passes *near* them, which is a claim about a road network the service has
never consulted. Straight-line distance ignores the Bosphorus. A corridor needs the driver's
actual path, and a published route has two endpoints and no path. Every cheap approximation here
produces a confident number that a member would reasonably act on, and the only honest version of
that number requires routing the service does not do.

### PostGIS geography is still read by nothing, so no index was earned

`places.point` remains `geography(Point, 4326)`, generated and server-private, exactly as ADR 0006
left it. Phase 12 does not query it — the discovery predicate is equality on two ids, which the
existing indexes already serve.

So **no GiST index was added**, and this is a decision rather than an omission: an index exists to
make a query fast, no such query exists, and adding one now would be storage and write cost with
no reader, defended by nothing but an intention. It arrives with the first spatial predicate, in
the same change, where its shape can be chosen against a real query plan.

### No `route_occurrences` — discovery searches plans

A weekday route is a plan: *every weekday at 08:25*, indefinitely. Materialising it into dated
rows requires choosing a horizon — fourteen days, thirty, ninety — and no such number was
available to be derived from anything. It would have been invented, and then every route's
lifetime, every backfill and every retention rule would inherit an invented constant.

Discovery therefore searches published plans directly and returns them as plans. A recurring route
appears as *weekdays at 08:25*, not as a list of dates. One-off routes carry their date, because
they have one.

Occurrences are deferred until a **concrete per-day consumer** requires them — a seat request for
a specific day, a trip on a specific date. That consumer will also determine the horizon, which is
the point: the horizon is a property of what reads the rows, and there was nothing to read them.

### Eligibility, and what it excludes

A route is discoverable only when it is somebody else's, still live, still ahead, and belongs to
a member who has a name:

* **Not the caller's own.** Being shown your own journey as a travel option is nonsense, and it is
  the fastest way to make a feed look broken.
* **Not cancelled.** A withdrawn route is withdrawn.
* **Not a past one-off.** Departure state is computed from the server clock in the route's own
  timezone; a one-off whose departure has passed is over. A recurring route has no such lapse.
* **Not a route whose owner has no profile.** The card names a driver. An account without a
  profile has no name to show, and Phase 12 will not fill that gap with a placeholder, an id, or
  a phone number.

The last exclusion is what makes discovery honest rather than what makes it convenient: it is the
reason there is no such thing as an anonymous driver on this surface.

### Ordering is recency, and says so

`(created_at DESC, id DESC)`, keyset. `id` is UUIDv7 and breaks ties deterministically, so a page
boundary cannot drop or repeat a route when two are published in the same instant.

This is **recently published**, and the client's copy says exactly that. It is not best,
recommended, closest, soonest or most compatible. Those words each describe a ranking, ranking is
a matching engine, and the matching engine is named but unspecified (see
`docs/architecture.md` — *Trust Score and matching*). Recency is the only ordering the service can
currently defend, so it is the only one it claims.

### Cursors are opaque and belong to one surface

A cursor is encrypted, carries no readable position, and is stamped with a surface version —
`rm.discovery.v1` for this endpoint, `rm.myroutes.v1` for My Routes. A cursor from the wrong
surface is refused rather than decoded.

The two feeds order by the same tuple, so a My Routes cursor would decode cleanly here and page
somebody else's journeys from a position established in their own. The version tag makes that a
rejection instead of a plausible wrong answer, and it lets either surface change its ordering
later without the other's saved cursors silently meaning something new.

### A page is filled, and an empty next cursor means exhausted

Eligibility is applied per candidate, so a naive page would return however many of its candidates
happened to survive — a member searching a busy pair could receive two results, then five, then
one, with a cursor each time, and conclude the route simply is not served.

So the endpoint scans forward until it has filled the requested limit or run out of candidates.
**A rejected candidate never consumes a public slot.** The returned cursor names the last
*returned* route, never the last one examined, so a caller can never be resumed past something
they were not shown. `next_cursor: null` therefore means genuine exhaustion of eligible results —
not that the scan gave up.

### The driver is a name and two letters

The payload exposes `display_name` and `initials`, from the real Profile domain, and nothing else.
No account id, no phone number, no public profile identifier, no join date.

`initials` stay **server-derived**. Turkish casing makes them a rule, not a formatting detail —
`i` uppercases to `İ`, `ı` to `I` — and a client deriving them would eventually render `IREM` for
`irem`. One authority computes them (`App\Profiles\DisplayName`); every client renders what it is
given. See ADR 0007 for the display-name policy itself, which this does not change.

### `seats_offered` is an offer, not inventory

It is the number of seats the driver said they have. It is **not** remaining, available or
unbooked seats, and it never decrements — nothing can request a seat yet, so there is nothing to
subtract. The client's copy says *offered* for the same reason.

Availability becomes a real quantity in Phase 13, when seat requests give it a source.

### What Phase 12 deliberately does not expose

No rating, no verification state or badge, no trust score, no approval rate, no compatibility
percentage, no walking minutes, no distance, no savings, no cost or fare, no trip count, no
shared-route count, no remaining-seat figure.

Each names a phase that has not happened. Individually any one of them could be faked with a
plausible number; together they are the difference between a service that reports what members
published and one that vouches for strangers it knows nothing about — on the single screen whose
purpose is deciding whether to get into somebody's car.

## Consequences

* Discovery works only between exact catalogue endpoints. A member whose journey is not expressible
  as one of those pairs finds nothing, and that is a truthful nothing.
* The client's Search sends two ids and nothing else. Seat, date, sort and trust filters were
  removed from the real path rather than collected and ignored — a control whose value the server
  never receives teaches a member to trust a filter that does not exist.
* Route Details remains fixture-backed, so a real discovered result deliberately does not navigate
  into it, and no seat can be requested from this surface. Both are Phase 13's to change.
* Revisit points, named so they stay decisions rather than drift: the GiST index and any spatial
  predicate; `route_occurrences` and its horizon, when a per-day consumer exists; ordering, when
  there is a ranking the service can defend; and exact-endpoint matching itself, if the catalogue
  ever grows fine-grained enough for two entries to mean one place.
