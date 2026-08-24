<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Place;
use App\Places\PilotCatalogue;
use Database\Seeders\PilotPlaceSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

/**
 * The catalogue is reference data, and reference data that moves is a defect.
 */
final class PilotCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_catalogue_seeds_the_pilot_places(): void
    {
        $this->seed(PilotPlaceSeeder::class);

        self::assertSame(
            count(PilotCatalogue::places()),
            Place::query()->count(),
        );

        foreach (PilotCatalogue::places() as $declared) {
            $stored = Place::query()->where('slug', $declared->slug)->sole();

            self::assertSame($declared->id, $stored->id);
            self::assertSame($declared->label, $stored->label);
            self::assertSame($declared->latitude, $stored->latitude);
            self::assertSame($declared->longitude, $stored->longitude);
        }
    }

    /**
     * Every id is written by hand, so every id is a chance to fat-finger one.
     */
    public function test_every_declared_id_is_a_distinct_uuid(): void
    {
        $ids = array_map(
            static fn ($place): string => $place->id,
            PilotCatalogue::places(),
        );
        $slugs = array_map(
            static fn ($place): string => $place->slug,
            PilotCatalogue::places(),
        );

        foreach ($ids as $id) {
            self::assertTrue(Str::isUuid($id), $id);
        }

        self::assertSame($ids, array_values(array_unique($ids)));
        self::assertSame($slugs, array_values(array_unique($slugs)));
    }

    /**
     * The property the whole design leans on.
     *
     * Routes reference places instead of copying their coordinates, which is
     * only safe while a place cannot move. Running the seed twice must be a
     * no-op, or "immutable" is a comment rather than a guarantee.
     */
    public function test_reseeding_identical_data_changes_nothing(): void
    {
        $this->seed(PilotPlaceSeeder::class);
        $before = Place::query()->orderBy('slug')->get()->toArray();

        $this->seed(PilotPlaceSeeder::class);

        self::assertSame($before, Place::query()->orderBy('slug')->get()->toArray());
    }

    /**
     * CARRIES WEIGHT. A moved place must stop the seed, not succeed quietly.
     *
     * `updateOrCreate` would make this pass silently, and a member's published
     * commute would start somewhere else without anybody being told.
     */
    public function test_a_known_slug_with_new_coordinates_is_refused(): void
    {
        $this->seed(PilotPlaceSeeder::class);
        $moved = PilotCatalogue::places()[0];

        DB::table('places')
            ->where('slug', $moved->slug)
            ->update(['latitude' => '41.000000']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/'.preg_quote($moved->slug, '/').'/');

        $this->seed(PilotPlaceSeeder::class);
    }

    public function test_a_known_slug_with_a_new_label_is_refused(): void
    {
        $this->seed(PilotPlaceSeeder::class);
        $renamed = PilotCatalogue::places()[1];

        DB::table('places')
            ->where('slug', $renamed->slug)
            ->update(['label' => 'Somewhere else entirely']);

        $this->expectException(RuntimeException::class);

        $this->seed(PilotPlaceSeeder::class);
    }

    /**
     * The generated column cannot be argued with, only verified.
     */
    public function test_the_spatial_point_matches_the_stored_coordinates(): void
    {
        $this->seed(PilotPlaceSeeder::class);
        $declared = PilotCatalogue::places()[0];

        $row = DB::selectOne(
            'select ST_Y(point::geometry) as lat, ST_X(point::geometry) as lon
             from places where slug = ?',
            [$declared->slug],
        );

        self::assertSame($declared->latitude, number_format((float) $row->lat, 6, '.', ''));
        self::assertSame($declared->longitude, number_format((float) $row->lon, 6, '.', ''));
    }

    public function test_the_database_refuses_an_impossible_latitude(): void
    {
        $this->expectException(QueryException::class);

        DB::table('places')->insert([
            'id' => '01991a00-0000-7000-8000-0000000000ff',
            'slug' => 'nowhere',
            'label' => 'Nowhere',
            'latitude' => '91.000000',
            'longitude' => '29.000000',
            'created_at' => now(),
        ]);
    }

    public function test_the_database_refuses_a_duplicate_slug(): void
    {
        $this->seed(PilotPlaceSeeder::class);
        $existing = PilotCatalogue::places()[0];

        $this->expectException(QueryException::class);

        DB::table('places')->insert([
            'id' => '01991a00-0000-7000-8000-0000000000fe',
            'slug' => $existing->slug,
            'label' => 'A second claim on the same identity',
            'latitude' => '41.000000',
            'longitude' => '29.000000',
            'created_at' => now(),
        ]);
    }

    /**
     * Immutability is a property of the code, not only of intent.
     *
     * A place with an update path is a place that can move, and the moment one
     * exists the reference-without-snapshot decision stops being safe. This
     * scans for the ways it would arrive, with comments stripped so the
     * explanations above do not report themselves.
     */
    public function test_nothing_in_the_application_updates_or_deletes_a_place(): void
    {
        $offenders = [];

        foreach (self::phpFilesIn(app_path()) as $path) {
            $code = self::withoutComments((string) file_get_contents($path));

            foreach (['Place::query()->update', '->delete()', 'updateOrCreate', 'upsert('] as $forbidden) {
                if (str_contains($code, $forbidden) && str_contains($code, 'Place')) {
                    $offenders[] = $path.' → '.$forbidden;
                }
            }
        }

        self::assertSame([], $offenders);
    }

    /**
     * @return list<string>
     */
    private static function phpFilesIn(string $directory): array
    {
        $files = [];

        /** @var \SplFileInfo $file */
        foreach (new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory)
        ) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private static function withoutComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }

            $code .= is_array($token) ? $token[1] : $token;
        }

        return $code;
    }
}
