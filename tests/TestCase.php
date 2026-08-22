<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * The only database the suite may ever touch.
     *
     * RefreshDatabase and migrate:fresh drop everything they find. A stray
     * .env, an exported DB_DATABASE or a mistyped phpunit.xml would point that
     * at the development database and destroy it silently. This aborts first.
     */
    private const REQUIRED_DATABASE = 'ridemate_test';

    protected function setUp(): void
    {
        parent::setUp();

        $database = config('database.connections.'.config('database.default').'.database');

        if ($database !== self::REQUIRED_DATABASE) {
            throw new RuntimeException(sprintf(
                'Refusing to run: tests are destructive and must target "%s", but the '
                .'active connection is "%s". Check phpunit.xml and any exported DB_* variables.',
                self::REQUIRED_DATABASE,
                is_scalar($database) ? (string) $database : gettype($database),
            ));
        }
    }
}
