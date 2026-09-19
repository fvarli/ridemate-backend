<?php

declare(strict_types=1);

namespace App\Otp\Email;

use InvalidArgumentException;

/**
 * The destination was not an address a passcode can be sent to.
 *
 * Thrown before anything is issued, so no challenge exists, no budget is
 * spent and no cooldown starts. That ordering is the whole point: an
 * unparseable address must not be able to consume a member's hourly cap.
 *
 * Distinct from `EmailDeliveryFailed`, which means the address was fine and
 * the provider was not. Collapsing the two would make a caller unable to tell
 * a bad input from a broken dependency, and the day this capability is behind
 * a public endpoint those are a 422 and a 500.
 *
 * Its message must never contain the address. It reaches the exception
 * renderer, which puts the message in the response body in a debug build, and
 * an address is exactly what an enumeration attempt is looking for.
 */
final class InvalidEmailAddress extends InvalidArgumentException {}
