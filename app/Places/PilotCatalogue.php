<?php

declare(strict_types=1);

namespace App\Places;

/**
 * The places RideMate's Istanbul pilot supports, and nothing else.
 *
 * WHERE THESE COORDINATES COME FROM
 *
 * OpenStreetMap, queried 2026-08-24, under the ODbL. Every entry names the OSM
 * node it came from, so any of them can be re-checked by anyone, and none of
 * them is a number somebody remembered.
 *
 * EVERY POINT IS A PLACE A PERSON CAN STAND
 *
 * Three metro stations, a ferry terminal and a shopping centre's main entrance.
 * A sixth candidate — the 42 Maslak complex — was researched and rejected: its
 * building polygon carries no entrance node, there is no bus stop within 350 m,
 * and two mapping sources disagree about its centre by roughly thirty metres.
 * Seeding that would have put a coordinate nobody can verify into the same
 * column as five that anyone can, and corridor matching would later treat them
 * as equals. The metro station serving Maslak is used instead, and it is named
 * for what it is.
 *
 * THE LABELS DESCRIBE THE COORDINATE, NOT THE OTHER WAY AROUND
 *
 * `Kadıköy, Vapur İskelesi` rather than the fixture's old `İskele Meydanı`,
 * because the point is the ferry terminal and OSM maps the neighbouring square
 * under a different name. `İTÜ Ayazağa, Metro İstasyonu` rather than
 * `Kampüsü`, because the campus is more than a kilometre across and its centre
 * is not where anyone meets. Naming a place after somewhere it is not would be
 * a small lie told to a member standing in the wrong street.
 *
 * THE COORDINATES ARE WRITTEN AT THE PRECISION THE COLUMN STORES
 *
 * Six decimal places, matching `numeric(9,6)`. OSM quotes seven; the seventh is
 * about a centimetre and the column would round it away on insert. Declaring
 * the rounded value here is not cosmetic: the seed compares what it holds
 * against what the database returns, and an unrounded literal would differ from
 * its own stored row and report drift on the second run.
 *
 * ADDING OR CHANGING AN ENTRY
 *
 * Adding one is ordinary: append it with a fresh id, a fresh slug and its
 * provenance. CHANGING one is not. Routes reference places, so moving a place
 * moves journeys that were already published against it, and the seeder refuses
 * rather than doing that quietly. If a place genuinely moves it is a new place
 * with a new slug, and the old row stays where the old routes still point.
 */
final class PilotCatalogue
{
    /**
     * @return list<PilotPlace>
     */
    public static function places(): array
    {
        return [
            new PilotPlace(
                id: '01991a00-0000-7000-8000-000000000001',
                slug: 'kadikoy-iskele',
                label: 'Kadıköy, Vapur İskelesi',
                latitude: '40.991397',
                longitude: '29.020443',
                provenance: 'The Kadıköy ferry terminal. OSM node 2446513149 '
                    .'(amenity=ferry_terminal, public_transport=station, '
                    .'surveyed check_date=2025-04-07).',
            ),
            new PilotPlace(
                id: '01991a00-0000-7000-8000-000000000002',
                slug: 'levent-metro',
                label: 'Levent, Metro İstasyonu',
                latitude: '41.076893',
                longitude: '29.013791',
                provenance: 'The M2 Levent station. OSM node 5218796192 '
                    .'(station=subway, network=İstanbul Metrosu, '
                    .'wikipedia=tr:Levent (M2, İstanbul Metrosu)).',
            ),
            new PilotPlace(
                id: '01991a00-0000-7000-8000-000000000003',
                slug: 'maslak-oto-sanayi',
                label: 'Maslak, Atatürk Oto Sanayi Metro İstasyonu',
                latitude: '41.118050',
                longitude: '29.024176',
                provenance: 'The M2 Atatürk Oto Sanayi station. OSM node '
                    .'2239404700 (station=subway, wheelchair=yes, '
                    .'wikidata=Q20312760).',
            ),
            new PilotPlace(
                id: '01991a00-0000-7000-8000-000000000004',
                slug: 'atasehir-palladium',
                label: 'Ataşehir, Palladium',
                latitude: '40.985359',
                longitude: '29.100116',
                provenance: "The shopping centre's main entrance. OSM node "
                    .'6926266120 (entrance=main).',
            ),
            new PilotPlace(
                id: '01991a00-0000-7000-8000-000000000005',
                slug: 'itu-ayazaga',
                label: 'İTÜ Ayazağa, Metro İstasyonu',
                latitude: '41.108072',
                longitude: '29.020970',
                provenance: 'The M2 İTÜ-Ayazağa station serving the campus. '
                    .'OSM node 7725394066 (station=subway).',
            ),
        ];
    }
}
