<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class HealthTest extends TestCase
{
    public function test_health_reports_the_process_is_alive(): void
    {
        $response = $this->getJson('/health');

        $response->assertOk();
        $response->assertJsonPath('status', 'ok');
        $response->assertJsonStructure(['status', 'service', 'environment', 'release']);
    }

    /**
     * The distinction that makes /health worth having.
     *
     * The connection is pointed at an unreachable port for this test only.
     * Nothing global is stopped: other projects on this machine share the same
     * PostgreSQL instance, and taking it down to prove a point would break
     * them.
     */
    public function test_health_answers_while_the_database_is_unreachable(): void
    {
        $this->breakTheDatabaseConnection();

        $this->getJson('/health')->assertOk()->assertJsonPath('status', 'ok');
    }

    public function test_health_exposes_nothing_about_the_infrastructure(): void
    {
        $body = $this->getJson('/health')->getContent();
        self::assertIsString($body);

        foreach (['password', 'DB_', 'pgsql', '127.0.0.1', '5432', 'postgres'] as $leak) {
            self::assertStringNotContainsStringIgnoringCase(
                $leak,
                $body,
                'a liveness probe is reachable by anything that can reach the service',
            );
        }
    }

    public function test_health_never_touches_the_database(): void
    {
        // Structural half of the guarantee above: a future edit that adds a
        // query would pass the behavioural test only until the database was
        // genuinely down in production.
        $source = file_get_contents(app_path('Http/Controllers/HealthController.php'));
        self::assertIsString($source);

        $health = substr($source, (int) strpos($source, 'public function health'));
        $health = substr($health, 0, (int) strpos($health, 'public function ready'));

        foreach (['DB::', 'DB ::', 'Schema::', 'Model', '->select('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $health);
        }
    }

    private function breakTheDatabaseConnection(): void
    {
        $connection = config('database.default');

        Config::set("database.connections.{$connection}.host", '127.0.0.1');
        // A port nothing listens on. Isolated to this process.
        Config::set("database.connections.{$connection}.port", 1);

        $this->app->forgetInstance('db');
        DB::clearResolvedInstances();
    }
}
