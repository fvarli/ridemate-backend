<?php

declare(strict_types=1);

namespace App\Places;

/**
 * One declared entry in the pilot catalogue.
 *
 * A value object rather than an array so the seed cannot quietly grow a sixth
 * key, and so `$place->latitude` means something a reader can check against the
 * migration's constraints.
 */
final readonly class PilotPlace
{
    public function __construct(
        public string $id,
        public string $slug,
        public string $label,
        public string $latitude,
        public string $longitude,
        /**
         * What the coordinate physically is, and where it came from.
         *
         * Carried in code rather than in a commit message because it is the
         * answer to the only question anyone will ever ask about these numbers:
         * "where did this come from, and is it the door or the middle of the
         * building?" Never persisted — the database stores the point, not the
         * argument for it.
         */
        public string $provenance,
    ) {}

    /**
     * Everything a stored row must match for the seed to consider it unchanged.
     *
     * `provenance` is excluded: it documents the choice, and rewording it must
     * not read as the place having moved.
     *
     * @return array<string, string>
     */
    public function persistedAttributes(): array
    {
        return [
            'slug' => $this->slug,
            'label' => $this->label,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }
}
