<?php

declare(strict_types=1);

namespace Tests\Contract;

use Illuminate\Http\JsonResponse;
use Illuminate\Testing\TestResponse;
use Opis\JsonSchema\Errors\ErrorFormatter;
use Opis\JsonSchema\Validator as SchemaValidator;
use Osteel\OpenApi\Testing\ValidatorBuilder;
use Osteel\OpenApi\Testing\ValidatorInterface;
use PHPUnit\Framework\Assert;
use Symfony\Component\Yaml\Yaml;

/**
 * Holds the implementation to openapi/openapi.yaml.
 *
 * The spec is the contract, so it cannot be a document that quietly stops
 * describing the service. Every response a test touches is validated against
 * what the spec actually says, which turns drift into a failing build instead
 * of a review comment somebody has to notice.
 *
 * Two levels, because they answer different questions:
 *
 *   assertMatchesOperation()  does this endpoint behave as documented?
 *   assertMatchesSchema()     does this body match a shared component schema?
 *
 * The second exists for responses that belong to no documented operation — an
 * unknown path under /api/v1 has no entry in `paths`, but its error body must
 * still match the shared Error schema. Documenting a catch-all operation to
 * make the first mechanism cover it would be inventing a contract for
 * "everything else".
 */
trait ValidatesTheContract
{
    private static ?ValidatorInterface $contractValidator = null;

    /**
     * @var array<string, mixed>|null
     */
    private static ?array $contractDocument = null;

    /**
     * Asserts the response matches the operation the spec documents.
     */
    /**
     * @param  TestResponse<JsonResponse>  $response
     */
    protected function assertMatchesOperation(
        TestResponse $response,
        string $path,
        string $method = 'get',
    ): void {
        self::$contractValidator ??= ValidatorBuilder::fromYamlFile(
            self::contractPath(),
        )->getValidator();

        // Throws on mismatch with the offending keyword, which is the useful
        // failure message.
        // The validator throws on mismatch, naming the offending keyword —
        // which is a better failure message than any assertion could give.
        // Recording the count keeps PHPUnit from calling the test risky
        // without asserting a tautology.
        self::$contractValidator->validate(
            $response->baseResponse,
            $path,
            $method,
        );

        $this->addToAssertionCount(1);
    }

    /**
     * Asserts the body validates against a named component schema.
     */
    /**
     * @param  TestResponse<JsonResponse>  $response
     */
    protected function assertMatchesSchema(TestResponse $response, string $schema): void
    {
        $document = self::contractDocument();
        $definition = $document['components']['schemas'][$schema] ?? null;

        Assert::assertNotNull($definition, "openapi.yaml documents no schema '{$schema}'");

        // Component $refs are relative to the document root, so the whole
        // document is registered and the schema referenced into it.
        $validator = new SchemaValidator;
        $validator->resolver()?->registerRaw(
            json_decode((string) json_encode($document), false),
            'https://ridemate.local/openapi',
        );

        $result = $validator->validate(
            json_decode((string) $response->getContent(), false),
            (object) ['$ref' => "https://ridemate.local/openapi#/components/schemas/{$schema}"],
        );

        if ($result->hasError()) {
            $error = $result->error();
            Assert::fail(sprintf(
                "Response does not match the documented %s schema:\n%s",
                $schema,
                $error === null ? 'unknown error' : json_encode(
                    (new ErrorFormatter)->format($error),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES,
                ),
            ));
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @return array<string, mixed>
     */
    protected static function contractDocument(): array
    {
        if (self::$contractDocument === null) {
            /** @var array<string, mixed> $parsed */
            $parsed = Yaml::parseFile(self::contractPath());
            self::$contractDocument = $parsed;
        }

        return self::$contractDocument;
    }

    protected static function contractPath(): string
    {
        return dirname(__DIR__, 2).'/openapi/openapi.yaml';
    }
}
