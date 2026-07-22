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
  ops/runtime/check-staging-read-only-prerequisites.php
  ops/runtime/check-staging-runtime-contract-loading.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeConfigOverlayTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeOutboxContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeBootstrapContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeBridgeLazinessContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeCliContractTest.php
  bot/tests/RuntimePrimaryStagingOneCommandReadOnlyCheckpointContractTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

for shell_file in \
  r \
  ops/runtime/check-staging-runtime-contract-loading.sh \
  ops/runtime/run-staging-read-only-checkpoint.sh; do
  bash -n "$shell_file"
  printf 'shell lint ok: %s\n' "$shell_file"
done

"$PHP_BIN" ops/runtime/check-staging-runtime-contract-loading.php >/dev/null
printf 'runtime contract loading ok: %s\n' 'ops/runtime/check-staging-runtime-contract-loading.php'

TESTS=(
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeConfigOverlayTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeOutboxContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeBootstrapContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeBridgeLazinessContractTest.php
  bot/tests/RuntimePrimaryStagingApiReadOnlySmokeCliContractTest.php
  bot/tests/RuntimePrimaryStagingOneCommandReadOnlyCheckpointContractTest.php
)

for test_file in "${TESTS[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'DB-primary staging API read-only smoke focused verification passed.\n'
