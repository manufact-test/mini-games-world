#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"

"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'

files=(
  bot/core/RuntimeConfigLoader.php
  bot/cutover/ProductionPreflightService.php
  bot/cutover/ProductionPreflightRunner.php
  bot/cutover/ProductionCutoverConfig.php
  bot/cutover/ProductionCutoverPackageManifest.php
  bot/cutover/ProductionCutoverPackageGuardTrait.php
  bot/cutover/ProductionCutoverExactPreflight.php
  bot/cutover/ProductionCutoverPrimaryStateSeeder.php
  bot/cutover/ProductionCutoverReleaseSmokeService.php
  bot/cutover/ProductionCutoverReleaseReceiptVerifier.php
  bot/cutover/ProductionRuntimePrimaryContract.php
  bot/cutover/ProductionCutoverRunner.php
  bot/cutover/ProductionCutoverRunTrait.php
  bot/cutover/ProductionCutoverPerformTrait.php
  bot/cutover/ProductionCutoverReleaseTrait.php
  bot/cutover/ProductionCutoverControlTrait.php
  bot/cutover/ProductionCutoverRecoveryPolicyTrait.php
  bot/cutover/ProductionCutoverDataTrait.php
  bot/cutover/ProductionCutoverRuntimeTrait.php
  bot/cutover/ProductionCutoverReportTrait.php
  bot/cutover/ProductionCutoverNoopTrait.php
  ops/deploy/production-cutover.php
  ops/deploy/production-cutover-smoke.php
  bot/tests/ProductionCutoverConfigPackageTest.php
  bot/tests/ProductionCutoverReleaseReceiptVerifierTest.php
  bot/tests/ProductionCutoverPackageContractTest.php
  bot/tests/ProductionCutoverPrimaryStateSeederContractTest.php
  bot/tests/RuntimeConfigLoaderCutoverControlTest.php
)

for file in "${files[@]}"; do
  "$PHP_BIN" -l "$PROJECT_ROOT/$file" >/dev/null
done

for test in \
  bot/tests/ProductionCutoverConfigPackageTest.php \
  bot/tests/ProductionCutoverReleaseReceiptVerifierTest.php \
  bot/tests/ProductionCutoverPackageContractTest.php \
  bot/tests/ProductionCutoverPrimaryStateSeederContractTest.php \
  bot/tests/RuntimeConfigLoaderCutoverControlTest.php \
  bot/tests/ProductionPreflightServiceTest.php \
  bot/tests/ProductionPrimaryRuntimeCoordinatorFoundationTest.php \
  bot/tests/ProductionPrimaryAtomicStorageAdapterTest.php \
  bot/tests/ProductionPrimaryEntrypointBootstrapGateTest.php \
  bot/tests/ProductionPrimaryEntrypointWiringContractTest.php \
  bot/tests/ProductionPrimaryRollbackExportCliContractTest.php \
  bot/tests/ProductionPrimaryLiveRollbackContractTest.php \
  bot/tests/RuntimeStorageRouterTest.php; do
  "$PHP_BIN" \
    -d auto_prepend_file="$PROJECT_ROOT/scripts/ci/php-strict.php" \
    "$PROJECT_ROOT/$test"
done

printf 'MGW_PRODUCTION_CUTOVER_PACKAGE=PASSED\n'
printf 'PACKAGE_VERSION=v1-mvp14-10e-cutover-recovery-package\n'
printf 'RUN_APPROVAL_SEPARATE=true\n'
printf 'RELEASE_APPROVAL_SEPARATE=true\n'
printf 'RELEASE_SMOKE_RECEIPT_REQUIRED=true\n'
printf 'PRIMARY_STATE_REVISION=1\n'
printf 'PRIMARY_OUTBOX_COMPLETED=true\n'
printf 'POST_ROUTE_STALE_JSON_ROLLBACK=false\n'
printf 'ROLLBACK_EXPORT_REQUIRED_AFTER_ROUTE=true\n'
printf 'LIVE_ROLLBACK_REQUIRED_AFTER_ROUTE=true\n'
printf 'DEPLOYED=false\n'
printf 'DATABASE_CONTACTED=false\n'
printf 'DATABASE_WRITE_EXECUTED=false\n'
printf 'PERSISTENT_CONFIG_CHANGED=false\n'
printf 'WEBHOOK_CHANGED=false\n'
printf 'CRON_CHANGED=false\n'
printf 'PRODUCTION_CHANGED=false\n'
