<?php

declare(strict_types=1);

namespace App\Otp\Sms;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\App;
use RuntimeException;

/**
 * Local development only. How a developer reads their own passcode.
 *
 * WHY NOT A LOG LINE
 *
 * The obvious implementation writes the code with Log::info, and it is exactly
 * what Phase 8's logging rule forbids. Application logs are shipped, aggregated
 * and retained; a passcode in one is a credential in a system nobody thinks of
 * as holding credentials. So this writes to a file OUTSIDE the logging
 * pipeline, which no log shipper is configured to collect.
 *
 * FAIL CLOSED
 *
 * The constructor refuses to exist anywhere but `local`. Not the send method —
 * the constructor — so a misconfigured staging or production environment fails
 * at resolution, on boot, rather than at the first member's sign-in attempt.
 * An environment check inside send() would leave a window where the wrong
 * sender is wired up and nobody knows until it runs.
 *
 * WHAT IS WRITTEN
 *
 * The passcode, and the last four digits of the number rather than the number
 * itself. Enough to tell two test handsets apart; not a phone book. The file is
 * overwritten every time, so no history accumulates, and it is created 0600
 * inside a 0700 directory. `storage/app/.gitignore` ignores everything that is
 * not `private/` or `public/`, so the directory cannot be committed.
 */
final class LocalEchoSmsSender implements SmsSender
{
    private const DIRECTORY = 'local-otp';

    private const FILE = 'latest.txt';

    public function __construct()
    {
        if (! App::environment('local')) {
            throw new RuntimeException(
                'LocalEchoSmsSender is local-only and must never be resolved elsewhere.',
            );
        }
    }

    public function sendPasscode(string $phoneE164, string $code): void
    {
        $directory = storage_path('app/'.self::DIRECTORY);

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new SmsDeliveryFailed('The local passcode directory could not be created.');
        }

        $path = $directory.'/'.self::FILE;

        $written = file_put_contents(
            $path,
            sprintf(
                "%s  ...%s  %s\n",
                CarbonImmutable::now()->toIso8601String(),
                substr($phoneE164, -4),
                $code,
            ),
        );

        if ($written === false) {
            throw new SmsDeliveryFailed('The local passcode file could not be written.');
        }

        // Best effort: on a filesystem that does not carry permissions this is
        // simply a no-op, and the file is already outside version control.
        @chmod($path, 0600);
    }

    /** Where a developer looks. Also what the tests assert is NOT written to. */
    public static function path(): string
    {
        return storage_path('app/'.self::DIRECTORY.'/'.self::FILE);
    }
}
