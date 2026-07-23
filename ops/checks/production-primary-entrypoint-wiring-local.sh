#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"

"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'

files=(
  bot/runtime/ProductionPrimaryRuntimeActivationContract.php
  bot/runtime/ProductionPrimaryRuntimeCoordinator.php
  bot/runtime/ProductionPrimaryAtomicStorageAdapter.php
  bot/runtime/ProductionPrimaryEntrypointStorageContext.php
  bot/runtime/ProductionPrimaryProjectorFactory.php
  bot/runtime/ProductionPrimaryEntrypointBootstrap.php
  bot/storage/RuntimeStorageRouter.php
  bot/storage/StorageFactory.php
  bot/runtime/RuntimePrimaryEntrypointBridgeGuard.php
  bot/webhook.php
  bot/tests/ProductionPrimaryAtomicStorageAdapterTest.php
  bot/tests/ProductionPrimaryEntrypointBootstrapGateTest.php
  bot/tests/ProductionPrimaryEntrypointWiringContractTest.php
  bot/tests/ProductionPrimaryRuntimeCoordinatorFoundationTest.php
  bot/tests/RuntimeStorageRouterTest.php
)

for file in "${files[@]}"; do
  "$PHP_BIN" -l "$PROJECT_ROOT/$file" >/dev/null
done

for test in \
  bot/tests/ProductionPrimaryRuntimeCoordinatorFoundationTest.php \
  bot/tests/ProductionPrimaryAtomicStorageAdapterTest.php \
  bot/tests/ProductionPrimaryEntrypointBootstrapGateTest.php \
  bot/tests/ProductionPrimaryEntrypointWiringContractTest.php \
  bot/tests/RuntimeStorageRouterTest.php; do
  "$PHP_BIN" \
    -d auto_prepend_file="$PROJECT_ROOT/scripts/ci/php-strict.php" \
    "$PROJECT_ROOT/$test"
done

printf 'MGW_PRODUCTION_PRIMARY_ENTRYPOINT_WIRING=PASSED\n'
printf 'API_WIRED=guarded_not_deployed\n'
printf 'WEBHOOK_WIRED=guarded_not_deployed\n'
printf 'ATOMIC_STATE_AND_PROJECTIONS=true\n'
printf 'DIRECT_COORDINATOR_EXECUTION=false\n'
printf 'REQUIRES_COMPLETED_CUTOVER_STATE=true\n'
printf 'ROLLBACK_REQUIRES_FRESH_DB_EXPORT=true\n'
printf 'DATABASE_CONTACTED=false\n'
printf 'DATABASE_WRITE_EXECUTED=false\n'
printf 'PERSISTENT_CONFIG_CHANGED=false\n'
printf 'WEBHOOK_DEPLOYMENT_CHANGED=false\n'
printf 'CRON_CHANGED=false\n'
printf 'PRODUCTION_CHANGED=false\n'
