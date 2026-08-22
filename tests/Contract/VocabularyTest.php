<?php

declare(strict_types=1);

namespace Tests\Contract;

use Tests\TestCase;

/**
 * Taxi and payment vocabulary may not enter the wire contract.
 *
 * This is a product boundary, not a style preference. RideMate is journey
 * sharing: the driver is making the trip regardless, and passengers share the
 * journey's legitimate costs. A field called `fare` or `driver_earnings` in a
 * published API describes a different product, one whose regulatory
 * characterisation is a question for counsel rather than for a schema author.
 * The client repository has enforced the same ban for seven phases.
 *
 * The check inspects IDENTIFIERS ONLY — path strings, schema property names,
 * parameter names, enum values, operationIds, component names. Prose in
 * `description` and `summary` stays free, so the contract can explain *why*
 * these words are forbidden without tripping its own guard. A naive grep
 * could not tell the two apart and would make the rule unusable.
 *
 * Matching is on whole snake_case tokens rather than substrings, so
 * `cost_share_per_person` passes while `fare_share` does not.
 */
final class VocabularyTest extends TestCase
{
    use ValidatesTheContract;

    /**
     * @var list<string>
     */
    private const FORBIDDEN = [
        'fare',
        'price',
        'pricing',
        'earnings',
        'income',
        'payout',
        'revenue',
        'charge',
        'commission',
        'invoice',
    ];

    public function test_no_forbidden_term_appears_in_a_contract_identifier(): void
    {
        $offenders = [];

        foreach (self::identifiers(self::contractDocument()) as $where => $identifiers) {
            foreach ($identifiers as $identifier) {
                foreach (self::forbiddenTokensIn($identifier) as $token) {
                    $offenders[] = "{$where}: {$identifier} (token '{$token}')";
                }
            }
        }

        self::assertSame([], $offenders);
    }

    public function test_the_guard_actually_catches_the_thing_it_bans(): void
    {
        // A guard nobody has seen fail is a guard nobody knows works. This
        // proves the token matcher rejects the realistic mistake and accepts
        // the approved term, which differ by one word.
        // Deliberately the same tokenizer the guard uses, so the two cannot
        // drift apart and leave this passing against a weaker rule.
        $reject = static fn (string $identifier): bool => self::forbiddenTokensIn($identifier) !== [];

        self::assertTrue($reject('fare_share'), 'the exact field the client still carries');
        self::assertTrue($reject('driverEarnings'), 'camelCase must tokenize too');
        self::assertTrue($reject('/trips/{id}/price'));
        self::assertTrue($reject('DriverPayout'));
        self::assertFalse($reject('cost_share_per_person'), 'the approved term');
        self::assertFalse($reject('discharge_reason'), 'substring matching would misfire here');
        self::assertFalse($reject('sharedRouteCount'));
    }

    /**
     * The forbidden tokens inside one identifier.
     *
     * Splits snake_case, kebab-case, path segments AND camelCase, because
     * `driverEarnings` is exactly as much a violation as `driver_earnings`
     * and a tokenizer that only handles separators would wave it through.
     *
     * @return list<string>
     */
    private static function forbiddenTokensIn(string $identifier): array
    {
        $separated = preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '_', $identifier) ?? $identifier;
        $tokens = preg_split('/[^a-z0-9]+/i', strtolower($separated)) ?: [];

        return array_values(array_intersect($tokens, self::FORBIDDEN));
    }

    /**
     * Every identifier position in the document, keyed by where it came from.
     *
     * @param  array<mixed>  $node
     * @return array<string, list<string>>
     */
    private static function identifiers(array $node, string $pointer = ''): array
    {
        $found = [];

        foreach ($node as $key => $value) {
            $here = $pointer.'/'.$key;

            if (is_string($key)) {
                // Path templates and component names are identifiers.
                if (str_starts_with($key, '/') || str_contains($pointer, 'schemas') || str_contains($pointer, 'properties')) {
                    $found[$here][] = $key;
                }
            }

            if (! is_array($value)) {
                // operationId and parameter names are identifier-valued.
                if (is_string($value) && in_array($key, ['operationId', 'name'], strict: true)) {
                    $found[$here][] = $value;
                }

                continue;
            }

            // Enum members are part of the contract vocabulary.
            if ($key === 'enum') {
                foreach ($value as $member) {
                    if (is_string($member)) {
                        $found[$here][] = $member;
                    }
                }

                continue;
            }

            $found = array_merge($found, self::identifiers($value, $here));
        }

        return $found;
    }
}
