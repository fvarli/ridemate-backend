<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Otp\Sms\LocalEchoSmsSender;
use Tests\TestCase;

/**
 * The developer's window onto their own passcode, and its blast radius.
 *
 * This sender exists because the obvious alternative — logging the code — puts
 * a credential into a pipeline that is shipped, aggregated and retained. The
 * assertions below are about keeping its reach small: local only, one line,
 * owner-readable, outside version control, and carrying as little of the
 * member's identity as still lets a developer tell two handsets apart.
 */
final class LocalEchoSmsSenderTest extends TestCase
{
    private const PHONE = '+905321234567';

    protected function setUp(): void
    {
        parent::setUp();

        // The suite runs as `testing`; PasscodeDeliveryTest asserts that the
        // sender refuses to exist there. Here we deliberately stand in a local
        // environment to exercise what it does when it is allowed to run.
        $this->app->detectEnvironment(static fn (): string => 'local');

        $this->removeFile();
    }

    protected function tearDown(): void
    {
        $this->removeFile();

        parent::tearDown();
    }

    private function removeFile(): void
    {
        if (is_file(LocalEchoSmsSender::path())) {
            unlink(LocalEchoSmsSender::path());
        }
    }

    public function test_it_writes_the_passcode_where_a_developer_can_read_it(): void
    {
        (new LocalEchoSmsSender)->sendPasscode(self::PHONE, '482913');

        self::assertFileExists(LocalEchoSmsSender::path());
        self::assertStringContainsString('482913', (string) file_get_contents(LocalEchoSmsSender::path()));
    }

    /**
     * Enough of the number to tell two test handsets apart, and no more.
     */
    public function test_it_records_only_the_last_digits_of_the_number(): void
    {
        (new LocalEchoSmsSender)->sendPasscode(self::PHONE, '482913');

        $contents = (string) file_get_contents(LocalEchoSmsSender::path());

        self::assertStringContainsString('4567', $contents);
        self::assertStringNotContainsString(self::PHONE, $contents);
        self::assertStringNotContainsString('905321234567', $contents);
    }

    /**
     * Overwrites rather than appends: no history of passcodes accumulates on
     * disk, and the newest is always the one on screen.
     */
    public function test_each_passcode_replaces_the_last(): void
    {
        $sender = new LocalEchoSmsSender;
        $sender->sendPasscode(self::PHONE, '111111');
        $sender->sendPasscode(self::PHONE, '222222');

        $contents = (string) file_get_contents(LocalEchoSmsSender::path());

        self::assertStringNotContainsString('111111', $contents);
        self::assertStringContainsString('222222', $contents);
        self::assertSame(1, substr_count(trim($contents), "\n") + 1);
    }

    public function test_the_file_is_not_world_readable(): void
    {
        (new LocalEchoSmsSender)->sendPasscode(self::PHONE, '482913');

        $mode = fileperms(LocalEchoSmsSender::path()) & 0777;

        self::assertSame(0, $mode & 0o077, 'the passcode file should be owner-only');
    }

    /**
     * It cannot be committed. `storage/app/.gitignore` ignores everything that
     * is not private/ or public/, and this lives beside them.
     */
    public function test_the_path_sits_under_an_ignored_directory(): void
    {
        self::assertStringContainsString(
            'storage/app/local-otp/',
            LocalEchoSmsSender::path(),
        );
    }
}
