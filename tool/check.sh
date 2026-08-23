#!/usr/bin/env bash
#
# RideMate backend — engineering quality gates.
#
# The single reproducible definition of "green", for local development and for
# CI. CI runs this script rather than a copy of its steps, so the two cannot
# drift apart.
#
#   ./tool/check.sh
#
# Every gate is fatal and the script stops at the first failure.
#
# PHP 8.4 is invoked explicitly. The system default on this machine is 8.2 and
# other projects depend on it; RideMate must never silently run on it.
#
# The OpenAPI contract and the forbidden-vocabulary rules are NOT separate
# steps here. They live inside the PHPUnit suite, because a contract check that
# can be run separately is a contract check somebody eventually forgets to run.

set -euo pipefail

cd "$(dirname "$0")/.."

PHP=${RIDEMATE_PHP:-php8.4}

if ! command -v "$PHP" >/dev/null 2>&1; then
    echo "error: $PHP not found. RideMate requires PHP 8.4." >&2
    exit 1
fi

VERSION=$("$PHP" -r 'echo PHP_MAJOR_VERSION . "." . PHP_MINOR_VERSION;')
if [ "$VERSION" != "8.4" ]; then
    echo "error: expected PHP 8.4, found $VERSION via '$PHP'." >&2
    exit 1
fi

echo "==> laravel pint (check only)"
"$PHP" vendor/bin/pint --test

echo "==> phpstan"
"$PHP" vendor/bin/phpstan analyse --no-progress

# Includes the contract tests, which validate real responses against
# openapi/openapi.yaml, and the vocabulary guard on the contract identifiers.
echo "==> phpunit"
"$PHP" vendor/bin/phpunit

echo
echo "All checks passed."
