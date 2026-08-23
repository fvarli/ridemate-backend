<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Support\RequestId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
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
        $response->assertJsonPath('error.message', 'The requested resource was not found.');
        $response->assertJsonStructure(['error' => ['code', 'message', 'request_id']]);
    }

    /**
     * The response never repeats what the caller sent.
     *
     * Laravel's own 404 text is "The route api/v1/x could not be found.",
     * which echoes the request back. A client already knows what it asked
     * for, so the only thing reflection adds is an unbounded, attacker-chosen
     * string in a body that other systems may go on to render or store.
     */
    public function test_the_404_never_reflects_the_requested_path(): void
    {
        $hostile = '/api/v1/'.urlencode('<script>alert(1)</script>-secret-token-xyz');

        $response = $this->getJson($hostile);
        $body = $response->getContent();
        self::assertIsString($body);

        $response->assertNotFound();
        $response->assertJsonPath('error.message', 'The requested resource was not found.');

        foreach (['script', 'secret-token-xyz', 'api/v1', 'route'] as $reflected) {
            self::assertStringNotContainsStringIgnoringCase($reflected, $body, "reflected: {$reflected}");
        }
    }

    /**
     * A missing record must not name the class that went looking for it.
     *
     * ModelNotFoundException maps to 404 and its message reads "No query
     * results for model [App\Models\Route] 1234" — an internal class name
     * and a record id. Phase 8 has no models, so this cannot happen yet; the
     * first one arrives in Phase 9 and this is what stops it then.
     */
    public function test_a_missing_model_leaks_neither_class_nor_identifier(): void
    {
        Route::middleware('api')->get('/api/v1/__missing', static function (): never {
            // The message Eloquent builds for a missing record, reproduced
            // literally. setModel() would need a real model class, and this
            // test is about the message, not the lookup.
            throw new ModelNotFoundException(
                'No query results for model [App\\Models\\Example] 01a02db5-dc94-73ba-a948-8e3ebf98f347'
            );
        });

        $response = $this->getJson('/api/v1/__missing');
        $body = $response->getContent();
        self::assertIsString($body);

        $response->assertNotFound();
        $response->assertJsonPath('error.code', 'not_found');
        $response->assertJsonPath('error.message', 'The requested resource was not found.');

        foreach (['App', 'Models', 'Example', '01a02db5', 'query results'] as $leak) {
            self::assertStringNotContainsStringIgnoringCase($leak, $body, "leaked: {$leak}");
        }
    }

    /**
     * The same fix, for the same defect.
     *
     * Laravel's 405 text echoes the attempted route and then lists the verbs
     * the route does accept, which maps the surface for whoever is probing.
     */
    public function test_the_405_reflects_neither_the_path_nor_the_allowed_verbs(): void
    {
        // /health is a real GET route, so any other verb reaches the 405 path.
        $response = $this->postJson('/health');
        $body = $response->getContent();
        self::assertIsString($body);

        $response->assertStatus(405);
        $response->assertJsonPath('error.code', 'method_not_allowed');
        $response->assertJsonPath(
            'error.message',
            'The request method is not supported for this resource.',
        );

        foreach (['health', 'GET', 'Supported methods'] as $reflected) {
            self::assertStringNotContainsString($reflected, $body, "reflected: {$reflected}");
        }
    }

    public function test_the_fixed_messages_do_not_change_with_debug(): void
    {
        // A contract that changes shape when APP_DEBUG flips is a contract
        // tested in one shape and shipped in another.
        foreach ([true, false] as $debug) {
            Config::set('app.debug', $debug);

            $this->getJson('/api/v1/does-not-exist')
                ->assertJsonPath('error.message', 'The requested resource was not found.');
        }
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
