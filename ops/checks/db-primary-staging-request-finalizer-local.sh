#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

bash ops/checks/db-primary-staging-entrypoint-selector-local.sh

FILES=(
  bot/runtime/RuntimePrimaryProjectionWorkerInterface.php
  bot/runtime/RuntimePrimaryProjectionAuditorInterface.php
  bot/runtime/RuntimePrimaryProjectionWorkerAdapter.php
  bot/runtime/RuntimePrimaryProjectionAuditorAdapter.php
  bot/runtime/RuntimePrimaryStagingRequestSessionConfig.php
  bot/runtime/RuntimePrimaryStagingRequestFinalizer.php
  bot/runtime/RuntimePrimaryStagingRequestSessionReadiness.php
  bot/tests/RuntimePrimaryStagingRequestSessionConfigTest.php
  bot/tests/RuntimePrimaryStagingRequestFinalizerTest.php
  bot/tests/RuntimePrimaryStagingRequestSessionReadinessTest.php
  bot/tests/RuntimePrimaryStagingRequestFinalizerContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

TESTS=(
  bot/tests/RuntimePrimaryStagingRequestSessionConfigTest.php
  bot/tests/RuntimePrimaryStagingRequestFinalizerTest.php
  bot/tests/RuntimePrimaryStagingRequestSessionReadinessTest.php
  bot/tests/RuntimePrimaryStagingRequestFinalizerContractTest.php
)

for test_file in "${TESTS[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary staging request finalizer focused verification passed.\n'
