<?php

declare(strict_types=1);

namespace App\Routes;

/**
 * The preferences a driver states about their own journey.
 *
 * Four flags, matching the four chips the approved screen offers. They are the
 * driver's own description of their car, not a matching rule: nothing filters
 * on them in this phase, and nothing enforces them at all — a rule is a thing
 * people agree to, not something software can police from here.
 *
 * They are persisted because the screen collects them. Publishing a journey
 * while silently discarding what the driver said about it would make four
 * controls that appear to configure something and do not, which is the exact
 * defect the client's own release guard exists to catch.
 *
 * POLICY REVIEW OUTSTANDING — `noPets`
 *
 * The client marks this rule as needing product, legal and safety review before
 * it is connected to anything that filters or matches. Carrying the marker here
 * keeps the question attached to the data rather than losing it at the API
 * boundary. This records an open question. It does not answer it, and nothing
 * in this codebase asserts what any law requires.
 */
final readonly class RideRules
{
    public function __construct(
        public bool $noSmoking,
        public bool $musicOk,
        public bool $noPets,
        public bool $quiet,
    ) {}

    /**
     * The rule whose product and legal review has not happened yet.
     *
     * Named as a constant so the marker is greppable from either repository,
     * the way the client names it in create_route_draft.dart.
     */
    public const NEEDS_POLICY_REVIEW = 'no_pets';

    /**
     * @return array<string, bool>
     */
    public function toColumns(): array
    {
        return [
            'rule_no_smoking' => $this->noSmoking,
            'rule_music_ok' => $this->musicOk,
            'rule_no_pets' => $this->noPets,
            'rule_quiet' => $this->quiet,
        ];
    }
}
