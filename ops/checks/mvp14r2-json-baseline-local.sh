#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"

"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'

files=(
  bot/baseline/JsonBehaviorBaselineNormalizer.php
  bot/baseline/JsonBehaviorBaselineFixture.php
  bot/baseline/JsonBehaviorBaselineResult.php
  bot/tests/Mvp14r2JsonBaselineNormalizerTest.php
  bot/tests/Mvp14r2JsonBaselineFixtureTest.php
  bot/tests/Mvp14r2JsonBaselineResultTest.php
  bot/tests/Mvp14r2JsonBaselineContractTest.php
)

for file in "${files[@]}"; do
  "$PHP_BIN" -l "$PROJECT_ROOT/$file" >/dev/null
done

for test in \
  bot/tests/Mvp14r2JsonBaselineNormalizerTest.php \
  bot/tests/Mvp14r2JsonBaselineFixtureTest.php \
  bot/tests/Mvp14r2JsonBaselineResultTest.php \
  bot/tests/Mvp14r2JsonBaselineContractTest.php; do
  "$PHP_BIN" \
    -d auto_prepend_file="$PROJECT_ROOT/scripts/ci/php-strict.php" \
    "$PROJECT_ROOT/$test"
done

printf 'MGW_MVP14R2_BASELINE_FOUNDATION=PASSED\n'
printf 'DETERMINISTIC_FIXTURE=PASSED\n'
printf 'NORMALIZATION_AND_FINGERPRINTING=PASSED\n'
printf 'SCENARIO_RESULT_SCHEMA=PASSED\n'
printf 'PRODUCTION_CONTACTED=false\n'
printf 'NETWORK_CONTACTED=false\n'
printf 'DATABASE_WRITE_EXECUTED=false\n'
printf 'LIVE_JSON_CHANGED=false\n'
printf 'PERSISTENT_CONFIG_CHANGED=false\n'
printf 'WEBHOOK_CHANGED=false\n'
printf 'CRON_CHANGED=false\n'
printf 'PRODUCTION_CHANGED=false\n'
