#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-staging-api-session-integration-local.sh

FILES=(
  bot/runtime/RuntimePrimaryStagingApiReadOnlySmoke.php
  bot/runtime/RuntimePrimaryStagingApiReadOnlySmokeConfigOverlay.php
  ops/runtime/run-staging-db-primary-api-read-only-smoke.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeConfigOverlayTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeOutboxContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeBootstrapContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeCliContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

TESTS=(
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeConfigOverlayTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeOutboxContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeBootstrapContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeCliContractTest.php
)

for test_file in "${TESTS[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary staging API read-only smoke focused verification passed.\n'
