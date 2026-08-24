<?php

declare(strict_types=1);

namespace App\Routes;

/**
 * Why a publication was refused, in terms the transport can map.
 *
 * Two cases, because they answer differently: the request described something
 * impossible, or it reused an id that already means something else. The first
 * is the caller's data, the second is the caller's bookkeeping.
 */
enum RefusalReason
{
    case InvalidJourney;
    case IdAlreadyUsed;
}
