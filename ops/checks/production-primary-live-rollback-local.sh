#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"

"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'

files=(
  bot/runtime/ProductionPrimaryRollbackArtifactIdentity.php
  bot/runtime/ProductionPrimaryLiveRollbackGate.php
  bot/runtime/ProductionPrimaryRuntimeOverlayWriter.php
  bot/runtime/ProductionPrimaryLiveRollbackStateStore.php
  bot/runtime/ProductionPrimaryLiveRollbackInputLoader.php
  bot/runtime/ProductionPrimaryLiveRollbackAuditorFactory.php
  bot/runtime/ProductionPrimaryLiveRollbackService.php
  bot/runtime/ProductionPrimaryLiveRollbackBootstrap.php
  bot/runtime/ProductionPrimaryLiveRollbackDependencies.php
  ops/runtime/run-production-primary-live-rollback.php
  bot/tests/ProductionPrimaryLiveRollbackGateTest.php
  bot/tests/ProductionPrimaryRuntimeOverlayWriterTest.php
  bot/tests/ProductionPrimaryLiveRollbackStateStoreTest.php
  bot/tests/ProductionPrimaryLiveRollbackContractTest.php
  bot/tests/ProductionPrimaryLiveRollbackServiceTest.php
)

for file in "${files[@]}"; do
  "$PHP_BIN" -l "$PROJECT_ROOT/$file" >/dev/null
done

for test in \
  bot/tests/ProductionPrimaryLiveRollbackGateTest.php \
  bot/tests/ProductionPrimaryRuntimeOverlayWriterTest.php \
  bot/tests/ProductionPrimaryLiveRollbackStateStoreTest.php \
  bot/tests/ProductionPrimaryLiveRollbackContractTest.php \
  bot/tests/ProductionPrimaryLiveRollbackServiceTest.php \
  bot/tests/ProductionPrimaryRollbackExportServiceTest.php \
  bot/tests/ProductionPrimaryRollbackExportMySqlIntegrationTest.php; do
  "$PHP_BIN" \
    -d auto_prepend_file="$PROJECT_ROOT/scripts/ci/php-strict.php" \
    "$PROJECT_ROOT/$test"
done

printf 'MGW_PRODUCTION_PRIMARY_LIVE_ROLLBACK=PASSED\n'
printf 'FINAL_DB_STATE_LOCK_REQUIRED=true\n'
printf 'OLD_AND_NEW_JSON_LOCKS_REQUIRED=true\n'
printf 'PREVIOUS_JSON_RETAINED=true\n'
printf 'DB_ROUTE_DISABLED_BEFORE_JSON_UNSEAL=true\n'
printf 'FAILURE_AFTER_ROUTE_DISABLE_REMAINS_SEALED=true\n'
printf 'LIVE_ROLLBACK_DEPLOYED=false\n'
printf 'DATABASE_WRITE_EXECUTED=false\n'
printf 'WEBHOOK_CHANGED=false\n'
printf 'CRON_CHANGED=false\n'
printf 'PRODUCTION_CHANGED=false\n'
