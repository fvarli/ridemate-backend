<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Readiness reports dependencies, and says nothing about how they are wired.
 *
 * Every failure below is injected inside this process — a connection pointed
 * at a dead port for the duration of one test. The machine-wide PostgreSQL is
 * shared with other projects on this machine and is never stopped or
 * restarted to make a test pass.
 */
final class ReadinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_ready_reports_ready_when_the_database_and_postgis_answer(): void
    {
        $response = $this->getJson('/ready');

        $response->assertOk();
        $response->assertExactJson([
            'status' => 'ready',
            'checks' => ['database' => 'ok', 'postgis' => 'ok'],
        ]);
    }

    public function test_ready_reports_not_ready_when_the_database_is_unreachable(): void
    {
        $this->breakTheConnection();

        $response = $this->getJson('/ready');

        $response->assertStatus(503);
        $response->assertJsonPath('status', 'not_ready');
        $response->assertJsonPath('checks.database', 'failed');
    }

    /**
     * PostGIS is a hard dependency, not a nice-to-have.
     *
     * Route matching is geographic, so a database that accepts connections but
     * cannot do geography cannot serve RideMate. A readiness probe that only
     * checked connectivity would report a green service that fails every real
     * query the moment discovery ships.
     */
    public function test_a_database_without_postgis_is_not_ready(): void
    {
        // The database answers; only the spatial capability is missing.
        DB::listen(static function (QueryExecuted $query): void {
            if (str_contains($query->sql, 'PostGIS_Version')) {
                throw new \RuntimeException('function postgis_version() does not exist');
            }
        });

        $response = $this->getJson('/ready');

        $response->assertStatus(503);
        $response->assertJsonPath('checks.database', 'ok');
        $response->assertJsonPath('checks.postgis', 'failed');
    }

    public function test_readiness_never_explains_why_a_dependency_failed(): void
    {
        $this->breakTheConnection();

        $body = $this->getJson('/ready')->getContent();
        self::assertIsString($body);

        // A readiness probe is reachable by anything that can reach the
        // service. It may say which check failed, never the DSN, the driver
        // message or the SQL. The reason lives in the request's log line.
        foreach ([
            'SQLSTATE', 'pgsql', 'PDO', 'password', 'postgres',
            '127.0.0.1', 'select', 'PostGIS_Version', 'Exception',
        ] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $body, "leaked: {$leak}");
        }
    }

    private function breakTheConnection(): void
    {
        $connection = config('database.default');

        Config::set("database.connections.{$connection}.host", '127.0.0.1');
        Config::set("database.connections.{$connection}.port", 1);

        $this->app->forgetInstance('db');
        DB::clearResolvedInstances();
    }
}
