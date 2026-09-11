<?php

declare(strict_types=1);

namespace App\Reviews;

/**
 * Which side of a shared journey wrote a review.
 *
 * Two cases, and they are sides of one seat request rather than kinds of
 * member: the same account is a driver on journeys it published and a passenger
 * on journeys it asked to join, sometimes on the same day.
 *
 * This is the only identity a review row stores. Both accounts are derived from
 * the seat request — see `ReviewParticipants` — so the role is what says which
 * derived account wrote it and which one it is about.
 *
 * WHAT IS ABSENT
 *
 * No `member`, `both` or `system`. A review is always one party writing about
 * the other, and a third case would be a claim nobody makes: RideMate does not
 * review anybody itself, and there is no anonymous submission — both parties
 * already know each other's display name from Phase 11, so anonymity would be a
 * fiction the rest of the data contradicts.
 */
enum ReviewerRole: string
{
    /** The member who published the journey, writing about a passenger. */
    case Driver = 'driver';

    /** The member whose seat request was accepted, writing about the driver. */
    case Passenger = 'passenger';

    /** The side this one is not: the party the review is about. */
    public function counterpart(): self
    {
        return match ($this) {
            self::Driver => self::Passenger,
            self::Passenger => self::Driver,
        };
    }
}
