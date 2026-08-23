<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Enables PostGIS.
 *
 * WHY THIS EXISTS IN A PHASE THAT CREATES NO TABLES
 *
 * Nothing uses geography yet, and Phase 8 otherwise refuses to create anything
 * without a consumer. This is the deliberate exception, because the risk it
 * retires is operational rather than schematic.
 *
 * PostGIS is an extension, not a table. Creating one needs rights an ordinary
 * application role may not have, and a managed PostgreSQL may not offer the
 * extension at all. Finding that out in the phase that implements corridor
 * matching — with the matching design already committed — would invalidate the
 * design. Enabling it now is a capability probe that costs one statement,
 * creates zero tables, zero columns and zero product assumptions, and turns
 * "does our database do geography" into a question already answered.
 *
 * WHY down() DOES NOTHING
 *
 * The obvious reverse is `DROP EXTENSION postgis CASCADE`, and it is a trap.
 * CASCADE drops every dependent object in the database — every geometry column
 * and every index built on one, across every migration that ever added them.
 * That is data loss wearing a rollback's clothing, and it would fire on a
 * routine `migrate:rollback` long after this migration stopped being the only
 * thing that cared about the extension.
 *
 * Without CASCADE the drop simply fails once anything depends on it, which
 * turns a rollback into an error instead.
 *
 * So this migration is deliberately irreversible. An extension is closer to
 * database configuration than to schema: rolling back application migrations
 * should not revoke a capability the database still has. Removing PostGIS is a
 * deliberate operational act, not a side effect of stepping back one migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        // IF NOT EXISTS so migrate:fresh is idempotent: dropping every table
        // leaves the extension in place, and this runs again on the next pass.
        DB::statement('CREATE EXTENSION IF NOT EXISTS postgis');
    }

    public function down(): void
    {
        // Intentionally empty. See the class comment: dropping the extension
        // would either cascade into real data loss or fail outright.
    }
};
