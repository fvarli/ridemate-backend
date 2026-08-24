<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Reference data only.
 *
 * The pilot place catalogue is not sample content: routes will reference it by
 * foreign key, so an environment without it cannot publish a journey at all.
 * That is why it is seeded rather than left to a fixture, and why it belongs in
 * every environment rather than development alone.
 *
 * Nothing else is seeded. There are no demo accounts and no example routes: a
 * placeholder member is indistinguishable from a real one once it is in the
 * database, and RideMate has spent every phase so far keeping fabricated people
 * out of surfaces that look real.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(PilotPlaceSeeder::class);
    }
}
