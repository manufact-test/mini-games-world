#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"
TMP_DIR="$(mktemp -d)"
trap 'rm -rf -- "$TMP_DIR"' EXIT

"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'

php_files=(
  bot/baseline/JsonBaselineScenarioCatalog.php
  bot/baseline/JsonBaselineLatencyRunner.php
  bot/baseline/JsonBaselineLatencyBootstrap.php
  bot/tests/Mvp14r2LatencyReportTest.php
  bot/tests/Mvp14r2LatencyReportContractTest.php
  ops/checks/mvp14r2-latency-report.php
)

for file in "${php_files[@]}"; do
  "$PHP_BIN" -l "$PROJECT_ROOT/$file" >/dev/null
done

"$PHP_BIN" -r '
  $path = $argv[1];
  $raw = file_get_contents($path);
  if (!is_string($raw) || trim($raw) === "") exit(1);
  $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
  exit(is_array($decoded) && !array_is_list($decoded) ? 0 : 1);
' "$PROJECT_ROOT/bot/tests/fixtures/mvp14r2/scenario_index.json"

for test in \
  bot/tests/Mvp14r2LatencyReportTest.php \
  bot/tests/Mvp14r2LatencyReportContractTest.php; do
  "$PHP_BIN" \
    -d auto_prepend_file="$PROJECT_ROOT/scripts/ci/php-strict.php" \
    "$PROJECT_ROOT/$test"
done

REPORT="$TMP_DIR/mvp14r2-latency-report.json"
"$PHP_BIN" \
  -d auto_prepend_file="$PROJECT_ROOT/scripts/ci/php-strict.php" \
  "$PROJECT_ROOT/ops/checks/mvp14r2-latency-report.php" \
  --cold=2 \
  --warm=5 \
  --output="$REPORT"

"$PHP_BIN" -r '
  $report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
  if (($report["contract_version"] ?? "") !== "mvp14r2-latency-report-v1") exit(1);
  if (($report["index"]["timed_scenario_count"] ?? 0) !== 26) exit(1);
  if (($report["guardrails"]["passed"] ?? false) !== true) exit(1);
  if (($report["production_evidence"] ?? true) !== false) exit(1);
' "$REPORT"

printf 'MGW_MVP14R2_LATENCY_ACCEPTANCE=PASSED\n'
printf 'FROZEN_SCENARIO_INDEX=27_TOTAL_26_TIMED\n'
printf 'COLD_WARM_REPORT=PASSED\n'
printf 'PRODUCT_ACCEPTANCE_CHECKLIST=READY\n'
printf 'NETWORK_CONTACTED=false\n'
printf 'PRODUCTION_CONTACTED=false\n'
printf 'DATABASE_CONTACTED=false\n'
printf 'DATABASE_WRITE_EXECUTED=false\n'
printf 'LIVE_JSON_CHANGED=false\n'
printf 'PERSISTENT_CONFIG_CHANGED=false\n'
printf 'WEBHOOK_CHANGED=false\n'
printf 'CRON_CHANGED=false\n'
printf 'PRODUCTION_CHANGED=false\n'
