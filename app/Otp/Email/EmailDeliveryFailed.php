<?php

declare(strict_types=1);

namespace App\Otp\Email;

use RuntimeException;

/**
 * Delivery did not happen.
 *
 * Its message must never contain the passcode or the recipient address: it
 * reaches the exception renderer, and in a debug build the renderer puts the
 * message in the response body. An address is also the thing an enumeration
 * attempt is looking for, so a failure that named one would answer a question
 * the API otherwise refuses to answer.
 */
final class EmailDeliveryFailed extends RuntimeException {}
