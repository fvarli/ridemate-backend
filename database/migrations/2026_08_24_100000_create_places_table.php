<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The pilot's meeting points.
 *
 * REFERENCE DATA, NOT USER CONTENT
 *
 * A member does not create a place. RideMate publishes a small, curated list of
 * points in Istanbul that a driver picks from, and the coordinates behind them
 * are the server's. That is a deliberate boundary: the client has no geocoder,
 * no maps vendor and no coordinates of its own, so if it supplied them they
 * would have to be invented somewhere. Storing coordinates is not geocoding.
 *
 * WHY EVERY POINT IS SOMEWHERE YOU CAN STAND
 *
 * Each row is a ferry terminal, a metro station or a building's main entrance —
 * never the centroid of a district or a polygon. A centroid is a fine label and
 * a bad meeting point: it can sit inside an eight-storey building. It also
 * becomes an input to corridor matching later, where a point nobody waits at
 * quietly degrades every match computed from it.
 *
 * WHY THERE IS NO UPDATE PATH
 *
 * Routes reference a place rather than copying its label and coordinates,
 * which is only safe while a place cannot move. Editing a row here would
 * silently rewrite the geography of every journey already published against it
 * — a member's commute changing because somebody corrected a label. So the
 * catalogue is seeded, the seeder refuses to overwrite a known slug with
 * different data, and nothing in the application updates or deletes a place.
 * Introducing place editing means deciding snapshot semantics first.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('places', function (Blueprint $table): void {
            // UUIDv7, but written explicitly by the seed rather than generated:
            // the same place must carry the same id in development, in CI and
            // in the pilot, because route rows point at it.
            $table->uuid('id')->primary();

            // The stable identity a human uses. Ids are for the database and
            // the wire; this is what a seed, a migration note or an operator
            // conversation names, and it is what the seeder detects drift
            // against. Deliberately not a transliteration of the label: the
            // label is presentation and may be corrected, the slug may not.
            $table->string('slug', 64)->unique();

            // Shown to the member, in Turkish, exactly as the catalogue
            // declares it. Named for what the coordinate actually is — the
            // ferry terminal rather than the square beside it.
            $table->string('label', 120);

            // Six decimal places is roughly a tenth of a metre, which is far
            // finer than a meeting point needs and cheaper than carrying a
            // float's rounding surprises into a distance query.
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);

            $table->timestampTz('created_at');
        });

        // The spatial value is GENERATED, so it cannot disagree with the
        // columns it comes from. The obvious alternative — write both and keep
        // them in step — fails the first time a row is fixed by hand, and the
        // failure is invisible: the label and the coordinates say one thing
        // while matching uses another.
        //
        // geography rather than geometry: distances come out in metres over
        // the spheroid, which is what "within 1.5 km of the corridor" means.
        // Nothing reads this column in Phase 10. It exists so that the phase
        // which adds corridor matching needs no backfill over rows a pilot has
        // already published, and its GiST index arrives with the first query
        // that would use one.
        DB::statement(
            'alter table places add column point geography(Point, 4326)
             generated always as (
                 ST_SetSRID(ST_MakePoint(longitude::double precision, latitude::double precision), 4326)::geography
             ) stored'
        );

        // A latitude outside ±90 is not a typo the database should accept: it
        // makes ST_MakePoint produce a location that silently is not on Earth.
        DB::statement(
            'alter table places add constraint places_latitude_check
             check (latitude between -90 and 90)'
        );

        DB::statement(
            'alter table places add constraint places_longitude_check
             check (longitude between -180 and 180)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('places');
    }
};
