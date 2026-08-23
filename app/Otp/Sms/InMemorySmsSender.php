<?php

declare(strict_types=1);

namespace App\Otp\Sms;

/**
 * The test double.
 *
 * Tests read passcodes from here rather than from a file or a log, which is
 * what lets the suite assert that the code appears in NEITHER of those.
 */
final class InMemorySmsSender implements SmsSender
{
    /** @var list<array{phone: string, code: string}> */
    private array $sent = [];

    private bool $failing = false;

    public function sendPasscode(string $phoneE164, string $code): void
    {
        if ($this->failing) {
            throw new SmsDeliveryFailed('The configured test double is failing on purpose.');
        }

        $this->sent[] = ['phone' => $phoneE164, 'code' => $code];
    }

    /** Makes every subsequent send fail, for the delivery-failure paths. */
    public function fail(): void
    {
        $this->failing = true;
    }

    /** @return list<array{phone: string, code: string}> */
    public function sent(): array
    {
        return $this->sent;
    }

    public function lastCode(): ?string
    {
        $last = end($this->sent);

        return $last === false ? null : $last['code'];
    }

    public function count(): int
    {
        return count($this->sent);
    }
}
