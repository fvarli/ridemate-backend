<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Place;
use App\Places\PilotCatalogue;
use App\Places\PilotPlace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Installs the pilot catalogue, and refuses to move a place that already exists.
 *
 * IDEMPOTENT, BUT NOT WITH updateOrCreate
 *
 * The obvious implementation is `Place::updateOrCreate(['slug' => …], $row)`,
 * and it is precisely wrong here. It would make a changed coordinate succeed
 * silently — which is the one outcome this seed exists to prevent, because
 * routes reference places. Correcting a label in this file and re-running the
 * seed would relocate journeys that members had already published, and nothing
 * would say so.
 *
 * So: identical data is a no-op, absent data is inserted, and DIFFERENT data
 * under a known slug throws. A seed that fails loudly during deployment is a
 * bad afternoon. A seed that quietly moved somebody's morning commute is a bug
 * nobody finds until a driver waits in the wrong place.
 *
 * If a place genuinely moves, it is a new place with a new slug. The old row
 * stays, because the old routes still point at it.
 */
class PilotPlaceSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PilotCatalogue::places() as $declared) {
            $stored = Place::query()->where('slug', $declared->slug)->first();

            if (! $stored instanceof Place) {
                $this->insert($declared);

                continue;
            }

            $this->refuseIfMoved($declared, $stored);
        }
    }

    private function insert(PilotPlace $declared): void
    {
        $place = new Place;

        // Assigned rather than mass-assigned: $fillable is empty, and the id is
        // the catalogue's own so the same place carries the same id in every
        // environment. HasUuids only generates a key that is still empty, so
        // setting it here is enough to keep it.
        $place->id = $declared->id;
        $place->slug = $declared->slug;
        $place->label = $declared->label;
        $place->latitude = $declared->latitude;
        $place->longitude = $declared->longitude;
        $place->created_at = CarbonImmutable::now();

        $place->save();
    }

    /**
     * @throws RuntimeException when a known slug arrives with different data.
     */
    private function refuseIfMoved(PilotPlace $declared, Place $stored): void
    {
        foreach ($declared->persistedAttributes() as $attribute => $value) {
            /** @var string $existing */
            $existing = $stored->getAttribute($attribute);

            if ($existing === $value) {
                continue;
            }

            throw new RuntimeException(sprintf(
                'Place "%s" is already seeded with %s "%s"; the catalogue now '
                .'declares "%s". Places are immutable because published routes '
                .'reference them: changing one here would move journeys that '
                .'members have already published. If this place has genuinely '
                .'moved, add it under a new slug instead.',
                $declared->slug,
                $attribute,
                $existing,
                $value,
            ));
        }

        if ($stored->id !== $declared->id) {
            throw new RuntimeException(sprintf(
                'Place "%s" is stored under id %s but the catalogue declares '
                .'%s. Ids are stable across environments precisely so routes '
                .'can reference them; reassigning one would orphan every route '
                .'that already points at the old id.',
                $declared->slug,
                $stored->id,
                $declared->id,
            ));
        }
    }
}
