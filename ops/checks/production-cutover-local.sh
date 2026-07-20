#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
PHP_BIN="${PHP_BIN:-php}"

cd "$PROJECT_ROOT"

FILES=(
  bot/core/RuntimeConfigLoader.php
  bot/cutover/ProductionCutoverConfig.php
  bot/cutover/ProductionCutoverRunner.php
  bot/cutover/ProductionCutoverRunTrait.php
  bot/cutover/ProductionCutoverPerformTrait.php
  bot/cutover/ProductionCutoverReleaseTrait.php
  bot/cutover/ProductionCutoverControlTrait.php
  bot/cutover/ProductionCutoverNoopTrait.php
  bot/cutover/ProductionCutoverDataTrait.php
  bot/cutover/ProductionCutoverRuntimeTrait.php
  bot/cutover/ProductionCutoverReportTrait.php
  bot/cutover/ProductionPreflightRunner.php
  bot/storage/RuntimeStorageRouter.php
  bot/services/FeatureFlagService.php
  bot/health.php
  ops/deploy/production-cutover.php
  bot/tests/RuntimeConfigLoaderTest.php
  bot/tests/ProductionCutoverConfigTest.php
  bot/tests/ProductionCutoverImportReportTest.php
  bot/tests/ProductionCutoverManualRollbackStateTest.php
  bot/tests/ProductionCutoverReleaseStateTest.php
  bot/tests/ProductionCutoverTerminalContractTest.php
  bot/tests/RuntimeStorageRouterTest.php
  bot/tests/ProductionCutoverRunnerContractTest.php
  bot/tests/ProductionCutoverRunnerStateTest.php
)

for file in "${FILES[@]}"; do
  "$PHP_BIN" -l "$file" >/dev/null
  printf 'lint ok: %s\n' "$file"
done

TESTS=(
  bot/tests/RuntimeConfigLoaderTest.php
  bot/tests/ProductionCutoverConfigTest.php
  bot/tests/ProductionCutoverImportReportTest.php
  bot/tests/ProductionCutoverManualRollbackStateTest.php
  bot/tests/ProductionCutoverReleaseStateTest.php
  bot/tests/ProductionCutoverTerminalContractTest.php
  bot/tests/RuntimeStorageRouterTest.php
  bot/tests/ProductionCutoverRunnerContractTest.php
  bot/tests/ProductionCutoverRunnerStateTest.php
)

for test_file in "${TESTS[@]}"; do
  "$PHP_BIN" "$test_file"
done

printf 'Production cutover focused verification passed.\n'
