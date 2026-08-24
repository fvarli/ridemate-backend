<?php

declare(strict_types=1);

namespace App\Http\Responses;

use App\Models\Place;
use Illuminate\Support\Collection;

/**
 * A place, as the client needs it: an identity and a name.
 *
 * A PROJECTION, NOT A SERIALIZATION
 *
 * The row behind this also carries a slug, a latitude, a longitude and a
 * spatial point. None of them are here, and the omission is the point rather
 * than an oversight. The client picks places by identity and renders them by
 * name; it has never needed to know where they are, and shipping coordinates
 * to every signed-in member would hand out a location dataset to answer a
 * question nobody asked.
 *
 * Naming the two fields explicitly, rather than serializing the model, is what
 * keeps that true. `$model->toArray()` would leak whatever a future migration
 * adds, silently, and every test that only checked the fields it expected would
 * still pass.
 */
final class PlacePayload
{
    /**
     * @return array{id: string, label: string}
     */
    public static function from(Place $place): array
    {
        return [
            'id' => $place->id,
            'label' => $place->label,
        ];
    }

    /**
     * @param  Collection<int, Place>  $places
     * @return array{places: list<array{id: string, label: string}>}
     */
    public static function catalogue(Collection $places): array
    {
        $projected = [];

        foreach ($places as $place) {
            $projected[] = self::from($place);
        }

        return ['places' => $projected];
    }
}
