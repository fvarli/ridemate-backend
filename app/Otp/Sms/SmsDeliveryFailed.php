<?php

declare(strict_types=1);

namespace App\Otp\Sms;

use RuntimeException;

/**
 * Delivery did not happen.
 *
 * Its message must never contain the passcode or the phone number: it reaches
 * the exception renderer, and in a debug build the renderer puts the message
 * in the response body.
 */
final class SmsDeliveryFailed extends RuntimeException {}
