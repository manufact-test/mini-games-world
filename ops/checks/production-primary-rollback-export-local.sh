#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"

"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'

files=(
  bot/runtime/ProductionPrimaryRollbackExportGate.php
  bot/runtime/ProductionPrimaryRollbackExportVerifier.php
  bot/runtime/ProductionPrimaryRollbackExportService.php
  bot/runtime/ProductionPrimaryRollbackRestoreService.php
  bot/runtime/ProductionPrimaryRollbackExportInputLoader.php
  bot/runtime/ProductionPrimaryRollbackAuditorFactory.php
  bot/runtime/ProductionPrimaryRollbackExportBootstrap.php
  ops/runtime/run-production-primary-rollback-export.php
  bot/tests/ProductionPrimaryRollbackExportGateTest.php
  bot/tests/ProductionPrimaryRollbackExportServiceTest.php
  bot/tests/ProductionPrimaryRollbackExportInputLoaderTest.php
  bot/tests/ProductionPrimaryRollbackExportCliContractTest.php
  bot/tests/ProductionPrimaryRollbackExportMySqlIntegrationTest.php
)

for file in "${files[@]}"; do
  "$PHP_BIN" -l "$PROJECT_ROOT/$file" >/dev/null
done

for test in \
  bot/tests/ProductionPrimaryRollbackExportGateTest.php \
  bot/tests/ProductionPrimaryRollbackExportServiceTest.php \
  bot/tests/ProductionPrimaryRollbackExportInputLoaderTest.php \
  bot/tests/ProductionPrimaryRollbackExportCliContractTest.php \
  bot/tests/ProductionPrimaryRollbackExportMySqlIntegrationTest.php; do
  "$PHP_BIN" \
    -d auto_prepend_file="$PROJECT_ROOT/scripts/ci/php-strict.php" \
    "$PROJECT_ROOT/$test"
done

printf 'MGW_PRODUCTION_PRIMARY_ROLLBACK_EXPORT=PASSED\n'
printf 'EXPORT_SOURCE=database_primary\n'
printf 'EXPORT_FORMAT=backup_manager_compatible_json\n'
printf 'STATE_ROW_LOCK_REQUIRED=true\n'
printf 'ALL_NINE_MODULE_PARITY_REQUIRED=true\n'
printf 'OUTBOX_COMPLETED_CHAIN_REQUIRED=true\n'
printf 'AUTHORIZATION_TTL_MAX_SECONDS=900\n'
printf 'ISOLATED_RESTORE_REQUIRED=true\n'
printf 'LIVE_JSON_REPLACEMENT_IMPLEMENTED=false\n'
printf 'DATABASE_WRITE_EXECUTED=false\n'
printf 'PERSISTENT_CONFIG_CHANGED=false\n'
printf 'WEBHOOK_CHANGED=false\n'
printf 'CRON_CHANGED=false\n'
printf 'PRODUCTION_CHANGED=false\n'
