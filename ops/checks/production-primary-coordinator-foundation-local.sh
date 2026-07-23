#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"

"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'
"$PHP_BIN" -l "$PROJECT_ROOT/bot/runtime/ProductionPrimaryRuntimeActivationContract.php"
"$PHP_BIN" -l "$PROJECT_ROOT/bot/runtime/ProductionPrimaryRuntimeCoordinator.php"
"$PHP_BIN" -l "$PROJECT_ROOT/bot/tests/ProductionPrimaryRuntimeCoordinatorFoundationTest.php"
"$PHP_BIN" \
  -d auto_prepend_file="$PROJECT_ROOT/scripts/ci/php-strict.php" \
  "$PROJECT_ROOT/bot/tests/ProductionPrimaryRuntimeCoordinatorFoundationTest.php"

printf 'MGW_PRODUCTION_PRIMARY_COORDINATOR_FOUNDATION=PASSED\n'
printf 'EXECUTION_ENABLED=false\n'
printf 'API_ENTRYPOINT_CHANGED=false\n'
printf 'WEBHOOK_ENTRYPOINT_CHANGED=false\n'
printf 'DATABASE_CONTACTED=false\n'
printf 'PERSISTENT_CONFIG_CHANGED=false\n'
printf 'CRON_CHANGED=false\n'
printf 'PRODUCTION_CHANGED=false\n'
