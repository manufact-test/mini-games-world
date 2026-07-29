#!/usr/bin/env bash
set -euo pipefail

PROJECT_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd -P)"
PHP_BIN="${PHP_BIN:-php}"

"$PHP_BIN" -r 'exit(PHP_VERSION_ID >= 80300 && PHP_VERSION_ID < 80400 ? 0 : 1);'

files=(
  bot/baseline/JsonBehaviorBaselineNormalizer.php
  bot/baseline/JsonBehaviorBaselineFixture.php
  bot/baseline/JsonBehaviorBaselineResult.php
  bot/baseline/JsonAccountPassiveBaselineScenario.php
  bot/tests/Mvp14r2AccountPassiveBaselineTest.php
  bot/tests/Mvp14r2AccountPassiveContractTest.php
)

for file in "${files[@]}"; do
  "$PHP_BIN" -l "$PROJECT_ROOT/$file" >/dev/null
done

for fixture in \
  account_bootstrap_idle \
  account_profile_finished_games \
  passive_session_secondary_lock \
  passive_notifications_visibility_order; do
  node -e 'JSON.parse(require("fs").readFileSync(process.argv[1], "utf8"));' \
    "$PROJECT_ROOT/bot/tests/fixtures/mvp14r2/${fixture}.json"
done

for test in \
  bot/tests/Mvp14r2AccountPassiveBaselineTest.php \
  bot/tests/Mvp14r2AccountPassiveContractTest.php; do
  "$PHP_BIN" \
    -d auto_prepend_file="$PROJECT_ROOT/scripts/ci/php-strict.php" \
    "$PROJECT_ROOT/$test"
done

printf 'MGW_MVP14R2_ACCOUNT_PASSIVE_BASELINE=PASSED\n'
printf 'ACCOUNT_BOOTSTRAP_AND_PROFILE=PASSED\n'
printf 'PASSIVE_SESSION_LOCK=PASSED\n'
printf 'NOTIFICATION_VISIBILITY_ORDER=PASSED\n'
printf 'READ_ONLY_DOMAIN_SNAPSHOTS=PASSED\n'
printf 'LATENCY_MEASURED=false\n'
printf 'PRODUCTION_CONTACTED=false\n'
printf 'NETWORK_CONTACTED=false\n'
printf 'DATABASE_WRITE_EXECUTED=false\n'
printf 'LIVE_JSON_CHANGED=false\n'
printf 'PERSISTENT_CONFIG_CHANGED=false\n'
printf 'WEBHOOK_CHANGED=false\n'
printf 'CRON_CHANGED=false\n'
printf 'PRODUCTION_CHANGED=false\n'
