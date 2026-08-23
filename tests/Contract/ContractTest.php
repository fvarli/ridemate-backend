<?php

declare(strict_types=1);

namespace Tests\Contract;

use App\Support\ApiError;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The spec and the service agree.
 *
 * These are not snapshot tests. Nothing here asserts a literal body; every
 * assertion runs the real response through the schema openapi.yaml documents,
 * so the failure mode they exist to catch is the implementation drifting away
 * from the published contract.
 */
final class ContractTest extends TestCase
{
    use RefreshDatabase;
    use ValidatesTheContract;

    public function test_the_specification_itself_is_valid(): void
    {
        // A contract that does not parse cannot govern anything, and the
        // failure should be a test rather than a confusing error inside
        // another assertion.
        $document = self::contractDocument();

        self::assertSame('3.1.0', $document['openapi'] ?? null);
        self::assertArrayHasKey('/health', $document['paths']);
        self::assertArrayHasKey('/ready', $document['paths']);
    }

    public function test_health_matches_its_documented_operation(): void
    {
        $this->assertMatchesOperation($this->getJson('/health'), '/health');
    }

    public function test_ready_success_matches_its_documented_operation(): void
    {
        // Needs a live database with PostGIS, so it runs against ridemate_test.
        $this->refreshDatabase();

        $response = $this->getJson('/ready');

        $response->assertOk();
        $this->assertMatchesOperation($response, '/ready');
    }

    public function test_ready_failure_matches_its_documented_operation(): void
    {
        // The 503 branch is documented, so it is validated like any other.
        // Failure is injected into this process only; the machine-wide
        // PostgreSQL other projects share is never touched.
        $this->breakTheDatabaseConnection();

        $response = $this->getJson('/ready');

        $response->assertStatus(503);
        $this->assertMatchesOperation($response, '/ready');
    }

    public function test_an_unknown_versioned_route_matches_the_error_schema(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $response->assertNotFound();
        $this->assertMatchesSchema($response, 'Error');
    }

    public function test_every_error_code_the_service_can_emit_is_documented(): void
    {
        // The client maps these to its own localized copy, so a code that
        // exists in PHP but not in the contract is a string the client cannot
        // translate — it would surface as an untranslated fallback.
        $documented = self::contractDocument()['components']['schemas']['ErrorCode']['enum'];

        $reflection = new \ReflectionClass(ApiError::class);
        $emitted = array_values($reflection->getConstants());

        sort($documented);
        sort($emitted);

        self::assertSame(
            $documented,
            $emitted,
            'openapi.yaml and ApiError disagree about the set of error codes',
        );
    }

    private function breakTheDatabaseConnection(): void
    {
        $connection = config('database.default');

        Config::set("database.connections.{$connection}.host", '127.0.0.1');
        Config::set("database.connections.{$connection}.port", 1);

        $this->app->forgetInstance('db');
        DB::clearResolvedInstances();
    }
}
