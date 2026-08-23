<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The suite is destructive, so it must never be able to reach development data.
 *
 * RefreshDatabase and migrate:fresh drop everything they find. An exported
 * DB_DATABASE, a stray .env or a mistyped phpunit.xml would silently point
 * that at the development database. TestCase::setUp aborts first; this proves
 * the separation actually holds rather than trusting the configuration.
 */
final class TestDatabaseGuardTest extends TestCase
{
    public function test_the_suite_runs_against_the_dedicated_test_database(): void
    {
        $connection = config('database.default');

        self::assertSame('pgsql', $connection, 'PostGIS cannot be exercised on SQLite');
        self::assertSame(
            'ridemate_test',
            config("database.connections.{$connection}.database"),
        );
    }

    public function test_the_development_database_is_never_the_target(): void
    {
        $connection = config('database.default');

        self::assertNotSame(
            'ridemate',
            config("database.connections.{$connection}.database"),
            'the destructive suite must never target the development database',
        );
    }

    public function test_the_guard_is_still_installed(): void
    {
        // Deleting the guard would make the two tests above pass right up
        // until the day something exported DB_DATABASE=ridemate.
        $source = file_get_contents(base_path('tests/TestCase.php'));
        self::assertIsString($source);

        self::assertStringContainsString('REQUIRED_DATABASE', $source);
        self::assertStringContainsString('ridemate_test', $source);
        self::assertStringContainsString('throw new RuntimeException', $source);
    }
}
