#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-staging-storage-resolver-local.sh

FILES=(
  bot/runtime/RuntimePrimarySyntheticRollback.php
  bot/runtime/RuntimePrimaryStagingSyntheticSuite.php
  ops/runtime/run-staging-db-primary-synthetic-suite.php
  bot/tests/RuntimePrimaryStagingSyntheticSuiteTest.php
  bot/tests/RuntimePrimaryStagingSyntheticSuiteContractTest.php
  bot/tests/RuntimePrimaryStagingSyntheticSuiteCliContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

TESTS=(
  bot/tests/RuntimePrimaryStagingSyntheticSuiteTest.php
  bot/tests/RuntimePrimaryStagingSyntheticSuiteContractTest.php
  bot/tests/RuntimePrimaryStagingSyntheticSuiteCliContractTest.php
)

for test_file in "${TESTS[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary staging synthetic suite focused verification passed.\n'
