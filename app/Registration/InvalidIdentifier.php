<?php

declare(strict_types=1);

namespace App\Registration;

use InvalidArgumentException;

/**
 * The value offered as a registration's email address or phone number was not
 * one.
 *
 * Thrown rather than returned, and the difference matters for the reason
 * `VerifyEmailPasscode` gives: a caller reaching this point has already
 * validated its own input, so an unparseable identifier is a bug rather than a
 * member typing something wrong. Answering it with an ordinary refusal would
 * hide the bug behind a legitimate-looking one.
 *
 * Its message must never contain the identifier. It reaches the exception
 * renderer, which puts the message in the response body in a debug build, and
 * an address or a number is exactly what an enumeration attempt is looking for.
 */
final class InvalidIdentifier extends InvalidArgumentException {}
