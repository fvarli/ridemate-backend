<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The database can do geography.
 *
 * RefreshDatabase migrates from nothing before this class runs, so the suite
 * passing at all is the proof that migrations work from an empty database.
 *
 * The schema's SHAPE is no longer asserted here. Phase 8's "there are no
 * product tables" guard became false the moment accounts existed, and rather
 * than delete it the allowlist it implied moved to SchemaAllowlistTest, which
 * keeps its actual purpose: no table appears without a decision.
 */
final class PostGisTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_postgis_extension_is_installed(): void
    {
        $installed = DB::selectOne(
            'select extversion from pg_extension where extname = ?',
            ['postgis'],
        );

        self::assertNotNull($installed, 'the migration should have created the extension');
    }

    public function test_postgis_answers_for_its_version(): void
    {
        $version = DB::selectOne('select PostGIS_Version() as version')->version;

        self::assertIsString($version);
        self::assertNotSame('', $version);
    }

    /**
     * Registered is not the same as working.
     *
     * pg_extension can list PostGIS while its own tables are missing — which
     * is exactly what a careless `migrate:fresh` would cause. Calling a real
     * geometry function is the only assertion that distinguishes the two.
     */
    public function test_geometry_functions_actually_execute(): void
    {
        $point = DB::selectOne(
            'select ST_AsText(ST_SetSRID(ST_MakePoint(?, ?), 4326)) as point',
            [29.02, 41.02],
        )->point;

        self::assertSame('POINT(29.02 41.02)', $point);
    }

    /**
     * The trap this phase had to walk into deliberately.
     *
     * migrate:fresh drops every table it finds, and spatial_ref_sys is a
     * table PostGIS owns. Dropping it leaves the extension registered and
     * broken. Laravel's Postgres builder excludes it by default, but that is a
     * framework default a future explicit `dont_drop` config could silently
     * override — so the outcome is pinned here rather than assumed.
     */
    public function test_postgis_survives_a_fresh_migration(): void
    {
        // RefreshDatabase has already dropped and re-migrated everything.
        $rows = DB::selectOne('select count(*) as count from spatial_ref_sys')->count;

        self::assertGreaterThan(
            0,
            (int) $rows,
            'spatial_ref_sys was emptied: PostGIS is registered but not usable',
        );
    }

    /**
     * The migration is irreversible on purpose.
     */
    public function test_rolling_back_never_drops_the_extension(): void
    {
        $migration = file_get_contents(
            database_path('migrations/2026_08_23_000000_enable_postgis_extension.php'),
        );
        self::assertIsString($migration);

        $down = substr($migration, (int) strpos($migration, 'public function down'));

        // Comments stripped first. The migration's own header explains why
        // DROP EXTENSION ... CASCADE is forbidden, so scanning raw text would
        // match the explanation rather than a defect — the same trap the
        // contract vocabulary guard avoids by inspecting identifiers only.
        $code = implode("\n", array_map(
            static function (string $line): string {
                $comment = strpos($line, '//');

                return $comment === false ? $line : substr($line, 0, $comment);
            },
            explode("\n", $down),
        ));

        // CASCADE would take every geometry column and every spatial index in
        // the database with it — data loss dressed as a rollback, fired by a
        // routine migrate:rollback.
        self::assertStringNotContainsStringIgnoringCase('DROP EXTENSION', $code);
        self::assertStringNotContainsStringIgnoringCase('CASCADE', $code);
    }
}
