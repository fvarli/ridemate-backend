<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * What the client says it is.
 *
 * Every field is optional and none is trusted. It is shown back to the member
 * when they review their sessions, and to nobody else. Nothing may authorise
 * on it: a client can claim any device name it likes.
 */
final readonly class DeviceDescription
{
    public function __construct(
        public ?string $name = null,
        public ?string $platform = null,
        public ?string $appVersion = null,
    ) {}

    public static function unknown(): self
    {
        return new self;
    }
}
