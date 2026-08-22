<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\RequestId;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

final class ErrorContractTest extends TestCase
{
    public function test_an_unknown_versioned_route_returns_the_standard_error(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'not_found');
        $response->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    public function test_details_are_absent_when_there_is_nothing_to_detail(): void
    {
        // `details` is for validation. Emitting an empty object everywhere
        // would make the field meaningless and force clients to test for it.
        $this->getJson('/api/v1/does-not-exist')
            ->assertJsonMissingPath('error.details');
    }

    public function test_the_error_body_carries_the_response_request_id(): void
    {
        $response = $this->getJson('/api/v1/does-not-exist');

        $header = $response->headers->get(RequestId::HEADER);
        self::assertIsString($header);
        $response->assertJsonPath('error.request_id', $header);
    }

    /**
     * The one that matters in production.
     *
     * A test-scoped route is registered here rather than shipping an endpoint
     * that throws: manufacturing a product endpoint to reach a status code is
     * exactly what the scope forbids, but the sanitisation still has to be
     * proven against a real unhandled exception.
     */
    public function test_an_unexpected_failure_leaks_nothing_when_debug_is_off(): void
    {
        Config::set('app.debug', false);

        Route::middleware('api')->get('/api/v1/__explodes', static function (): never {
            throw new RuntimeException('connection to 10.0.0.7 failed: password authentication failed for user "postgres"');
        });

        $response = $this->getJson('/api/v1/__explodes');

        $response->assertStatus(500);
        $response->assertJsonPath('error.code', 'internal_error');
        $response->assertJsonPath('error.message', 'An unexpected error occurred.');

        $body = $response->getContent();
        self::assertIsString($body);

        foreach ([
            'password authentication',
            '10.0.0.7',
            'RuntimeException',
            'vendor/',
            'app/',
            '.php',
            '#0',
            'stack',
        ] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $body, "leaked: {$leak}");
        }
    }

    public function test_debug_builds_still_show_developers_the_real_message(): void
    {
        Config::set('app.debug', true);

        Route::middleware('api')->get('/api/v1/__explodes', static function (): never {
            throw new RuntimeException('the real cause');
        });

        $this->getJson('/api/v1/__explodes')
            ->assertStatus(500)
            ->assertJsonPath('error.message', 'the real cause');
    }
}
