<?php

declare(strict_types=1);

namespace App\Otp\Email;

/**
 * The test double, and only that.
 *
 * No driver value selects it — the container knows nothing about it, and a
 * test that wants it binds it by hand. Tests read passcodes from here rather
 * than from a file or a log, which is what lets the suite assert that a code
 * appears in NEITHER.
 *
 * There is deliberately no local-development sibling of this class. The SMS
 * seam has one because a developer signs in over SMS every day; no developer
 * workflow issues an email passcode, and an echo file would have to write a
 * full address and a live code to disk — `LocalEchoSmsSender` can truncate a
 * phone number to its last four digits, and an address has no equivalent
 * safe half.
 */
final class InMemoryEmailSender implements EmailSender
{
    /** @var list<array{email: string, code: string}> */
    private array $sent = [];

    private bool $failing = false;

    public function sendPasscode(string $emailAddress, string $code): void
    {
        if ($this->failing) {
            throw new EmailDeliveryFailed('The configured test double is failing on purpose.');
        }

        $this->sent[] = ['email' => $emailAddress, 'code' => $code];
    }

    /** Makes every subsequent send fail, for the delivery-failure paths. */
    public function fail(): void
    {
        $this->failing = true;
    }

    /** @return list<array{email: string, code: string}> */
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
