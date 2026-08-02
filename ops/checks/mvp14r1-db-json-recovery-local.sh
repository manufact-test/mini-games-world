#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"

"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'

files=(
  ops/runtime/inspect-mvp14r1-recovery-readiness.php
  ops/runtime/run-production-primary-rollback-export.php
  ops/runtime/run-production-primary-live-rollback.php
  bot/runtime/ProductionPrimaryRollbackExportGate.php
  bot/runtime/ProductionPrimaryRollbackExportVerifier.php
  bot/runtime/ProductionPrimaryRollbackExportService.php
  bot/runtime/ProductionPrimaryRollbackSnapshotMaterializer.php
  bot/runtime/ProductionPrimaryRollbackMaterializedStateConnection.php
  bot/runtime/ProductionPrimaryRollbackMaterializedExportService.php
  bot/runtime/ProductionPrimaryRollbackRestoreService.php
  bot/runtime/ProductionPrimaryLiveRollbackGate.php
  bot/runtime/ProductionPrimaryLiveRollbackService.php
  bot/tests/Mvp14r1RecoveryReadinessInspectorTest.php
  bot/tests/Mvp14r1MaterializedRollbackExportContractTest.php
  bot/tests/ProductionPrimaryRollbackSnapshotMaterializerTest.php
)

for file in "${files[@]}"; do
  "$PHP_BIN" -l "$PROJECT_ROOT/$file" >/dev/null
done

tests=(
  bot/tests/Mvp14r1RecoveryReadinessInspectorTest.php
  bot/tests/Mvp14r1MaterializedRollbackExportContractTest.php
  bot/tests/ProductionPrimaryRollbackSnapshotMaterializerTest.php
  bot/tests/ProductionPrimaryRollbackExportGateTest.php
  bot/tests/ProductionPrimaryRollbackExportRequestIdCaseTest.php
  bot/tests/ProductionPrimaryRollbackExportServiceTest.php
  bot/tests/ProductionPrimaryRollbackExportInputLoaderTest.php
  bot/tests/ProductionPrimaryRollbackExportCliContractTest.php
  bot/tests/ProductionPrimaryRollbackExportMySqlIntegrationTest.php
  bot/tests/ProductionPrimaryLiveRollbackContractTest.php
  bot/tests/ProductionPrimaryLiveRollbackGateTest.php
  bot/tests/ProductionPrimaryLiveRollbackServiceTest.php
  bot/tests/ProductionPrimaryLiveRollbackStateStoreTest.php
  bot/tests/ProductionPrimaryRuntimeOverlayWriterTest.php
)

for test in "${tests[@]}"; do
  "$PHP_BIN" \
    -d auto_prepend_file="$PROJECT_ROOT/scripts/ci/php-strict.php" \
    "$PROJECT_ROOT/$test"
done

printf 'MGW_MVP14R1_RECOVERY_PACKAGE=PASSED\n'
printf 'STATIC_READINESS_INSPECTOR=PASSED\n'
printf 'DB_TO_JSON_EXPORT_CONTRACT=PASSED\n'
printf 'READ_ONLY_ACCOUNT_MATERIALIZATION=PASSED\n'
printf 'ISOLATED_RESTORE_CONTRACT=PASSED\n'
printf 'TEMPORARY_JSON_RUNTIME_CONTRACT=PASSED\n'
printf 'DB_TO_JSON_EXPORT_EXECUTED=false\n'
printf 'PRODUCTION_RUNTIME_CHANGED=false\n'
printf 'DATABASE_WRITE_EXECUTED=false\n'
printf 'LIVE_JSON_CHANGED=false\n'
printf 'WEBHOOK_CHANGED=false\n'
printf 'CRON_CHANGED=false\n'
printf 'PRODUCTION_CHANGED=false\n'
